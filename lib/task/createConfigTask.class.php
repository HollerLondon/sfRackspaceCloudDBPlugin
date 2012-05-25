<?php

/**
 * Symfony 1.4 task for database config creation to work with Rackspace CLoudDB
 */
class createConfigTask extends sfBaseTask
{

    /** @var RackspaceCloudDB $rackspacer */
    protected $rackspacer, $auth, $instanceId, $databaseName, $userName, $savedInstance, $savedDatabase, $savedUser;
    protected $createInfo = array();

    protected function configure()
    {
        $this->addArguments(array(
            new sfCommandArgument('account-id', sfCommandArgument::REQUIRED, 'Rackspace account id to use'),
            new sfCommandArgument('instance-id', sfCommandArgument::OPTIONAL, 'Rackspace Cloud DB instance id for the database to use if you have instances defined'),
        ));

        $this->addOptions(array(
            new sfCommandOption('geo', null, sfCommandOption::PARAMETER_OPTIONAL, 'Type of geographic endpoint to use (lon|ord|dfw)', 'lon'),
            new sfCommandOption('connector-type', null, sfCommandOption::PARAMETER_OPTIONAL, 'DB connection library to use (propel|doctrine)', 'doctrine'),
            new sfCommandOption('db-env', null, sfCommandOption::PARAMETER_OPTIONAL, 'Name of the environment to use for databases.yml', 'all'),
        ));

        $this->namespace = 'rackspace';
        $this->name = 'generate-config';

        $this->briefDescription = 'Interactive generation of databases.yml to use with Rackspace Cloud DB';

        $this->detailedDescription = <<<EOF
The [rackspace:generate-config|INFO] task generates a database config file in your
[config/|COMMENT] directory if [databases.yml|COMMENT] does not exist already.

It is an interactive task which would allow you to create instances, databases and users or select
from existing ones.
EOF;
    }

    /**
     * Executes the current task.
     *
     * @param array    $arguments  An array of arguments
     * @param array    $options    An array of options
     *
     * @return integer 0 if everything went fine, or an error code
     */
    protected function execute($arguments = array(), $options = array())
    {
        $this->rackspacer = new RackspaceCloudDB($arguments['account-id'], $options['geo']);

        $this->logBlock('Lets authenticate', 'INFO');
        $username = $this->ask('Whats your username?');
        $password = $this->ask('Whats your API key?');

        $this->auth = $this->rackspacer->authenticate($username, $password);
        $this->logBlock(sprintf('Success. Your auth token is valid till %s (%s)', $this->auth->tokenExpires, $this->auth->tokenId), 'INFO');

        // do some questions about instances if none was specified
        if (empty($arguments['instance-id'])) {
            $createNewInstance = false;
            $instances = $this->rackspacer->getInstances();

            // if we didn't get any lets ask about creation
            if (empty($instances)) {
                $createNewInstance = $this->askConfirmation(array('You do not seem to have any instances defined.', 'Would you like to create a new one? [Y/n]'));
            } else {
                // see if he chooses one and if not create one
                $choices = array();
                // ask behaves oddly with choices being 0 so lets start with 1
                foreach ($instances as $k => $v) {
                    $choices[] = $k + 1 . ". $v->name ($v->id)";
                }

                $choices[] = 'Please choose instance from the list or any other key to create a new one';

                $instanceChoice = $this->ask($choices);

                // if some instance was chosen, all good
                if (isset($instances[$instanceChoice - 1])) {
                    $this->savedInstance = $instances[$instanceChoice - 1];
                    $this->instanceId = $this->savedInstance->id;
                } else {
                    // otherwise flag it for new creation
                    $createNewInstance = true;
                }
            }

            // ask some questions about new instance
            if ($createNewInstance) {
                $this->setupInstance();
            } elseif (empty($this->instanceId)) {
                // or just bail
                $this->end();
            }
        } else {
            $this->logBlock('Will use instance DB ' . $arguments['instance-id'], 'INFO');
            $this->instanceId = $arguments['instance-id'];
        }

        // if no instance was chosen, we can assume that we are creating a new one
        // so go straight to asking about new DB creation
        if (empty($this->instanceId)) {
            $this->setupDatabase();
        } else {
            // if we got instance setup, we can ask if they want to use existing database
            if ($this->askConfirmation(array(
                'Since you have a running instance you can choose existing database or create a new one.',
                'Would you like to create new database? [Y/n]',
            ))
            ) {
                $this->setupDatabase();
            } else {
                // if not, lets list them and see if user wants to create one
                $databases = $this->rackspacer->getDatabases($this->instanceId);

                $question = array();
                if (empty($databases)) {
                    $question[] = "You don't seem to have any databases setup for this instance.";
                    $question[] = ' ';
                } else {
                    foreach ($databases as $k => $v) {
                        $question[] = $k + 1 . ". $v->name";
                    }
                }
                $question[] = "Please select a database from the list or press any key to create new one.";

                $databaseChoice = $this->ask($question);
                if (isset($databases[$databaseChoice - 1])) {
                    $this->savedDatabase = $databases[$databaseChoice - 1];
                    $this->databaseName = $this->savedDatabase->name;
                } else {
                    $this->setupDatabase();
                }
            }
        }

        // lets setup a user then
        // although not documented, it seems that when you POST a user with same name as existing one
        // password is replaced but databases are appended. hence its a sort of an edit
        // allowing us to add access to databases created after the user
        if (empty($this->instanceId)) {
            $this->setupUser();
        } else {
            if ($this->askConfirmation(array(
                'It is possible to use an existing user or create a new one',
                'Would you like to create a new user for your database? [Y/n]'
            ))
            ) {
                $this->setupUser();
            } else {
                $users = $this->rackspacer->getUsers($this->instanceId); //, $this->databaseName);

                $question = array();
                if (empty($users)) {
                    $question[] = 'There are no users who can access your database';
                } else {
                    foreach ($users as $k => $v) {
                        $question[] = $k + 1 . ". $v->name";
                    }
                }

                $question[] = 'Please select a user from the list. Any other key to create one';

                $userChoice = $this->ask($question);

                if (isset($users[$userChoice - 1])) {
                    $user = $users[$userChoice - 1];
                    $dbToAccess = ($this->databaseName) ? : $this->createInfo['database']['name'];
                    // if he can access it, just use
                    if ($user->canAccessDatabase($dbToAccess)) {
                        $this->userName = $users[$userChoice - 1]->name;
                    } else {
                        // otherwise update existing user to have permission
                        // which is basically like creating a new user but without a password
                        $this->setupUser($user->name);
                    }
                } else {
                    $this->setupUser();
                }
            }
        }

        // by now we should have all the info we need
        // if you don't like it, just run the task again
        $summary = $this->getSummary();
        $summary[] = 'Is everything looking good? [y/N]';

        if (!$this->askConfirmation($summary, 'QUESTION', false)) {
            $this->end();
        }

        // doSave does all the logic what to push out to rackspace
        // if anything.
        $this->logBlock('Updating Rackspace Cloud Database as needed...', 'INFO');
        $this->doSave();

        // if we didn't explode by now its all good. display what new db config will look like
        $this->logBlock('Sample databases.yml file:', 'INFO');

        $configArray = ($options['connector-type'] == 'propel') ? $this->getPropelConfigArray($options['db-env']) : $this->getDoctrineConfigArray($options['db-env']);
        $configYml = sfYaml::dump($configArray, 10);

        $this->log($configYml);

        // and attempt to save with confirmation
        return $this->doConfigSave($configYml);
    }

    /**
     * Display set of questions about creating new DB instance
     *
     * @TODO add listing of flavors and some validation?
     *
     * @return bool
     */
    protected function setupInstance()
    {
        $this->createInfo['instance']['name'] = $this->ask('What is the name of your new database instance?');
        $this->createInfo['instance']['volumeSize'] = (int)$this->ask('What is the volume size in gigabytes (GB)? The value specified must be between 1 and 10 [1]', 'QUESTION', 1);

        $flavours = array(
            'FLAVORS:',
            '1. Tiny (512)',
            '2. Small (1024)',
            '3. Medium (2048)',
            '4. Large (4096)',
            'What is the flavor of your new database instance? [1]',
        );
        $this->createInfo['instance']['flavorId'] = (int)$this->ask($flavours, 'QUESTION', 1);

        $summary = array(
            ' ',
            'Name: ' . $this->createInfo['instance']['name'],
            'Size: ' . $this->createInfo['instance']['volumeSize'] . 'GB',
            'Flavor: ' . $this->createInfo['instance']['flavorId'],
            '                                                 ',
            'Is this setup correct? [Y/n]',
            ' ',
        );

        if ($this->askConfirmation($summary)) {
            return true;
        } else {
            return $this->setupInstance();
        }
    }

    /**
     * Ask things related to new database creation
     *
     * @return bool
     */
    protected function setupDatabase()
    {
        $this->createInfo['database']['name'] = $this->ask('What is the name of your new database?');
        $this->createInfo['database']['character_set'] = $this->ask('What is the charset of your new database? [utf8]', 'QUESTION', 'utf8');
        $this->createInfo['database']['collate'] = $this->ask('What is the database collation? [utf8_general_ci]', 'QUESTION', 'utf8_general_ci');

        $summary = array(
            ' ',
            'Name: ' . $this->createInfo['database']['name'],
            'Charset: ' . $this->createInfo['database']['character_set'],
            'Collate: ' . $this->createInfo['database']['collate'],
            '                                                 ',
            'Is this setup correct? [Y/n]',
            ' ',
        );

        if ($this->askConfirmation($summary)) {
            return true;
        } else {
            return $this->setupDatabase();
        }
    }

    /**
     * Ask a few questions about user creation/update
     * @param null $username
     * @return bool
     */
    protected function setupUser($username = null)
    {
        $dbName = ($this->databaseName) ? : $this->createInfo['database']['name'];
        $this->createInfo['user']['name'] = ($username !== null) ? $username : $this->ask('What is the name of your new user?');
        $this->createInfo['user']['password'] = $this->ask('What is the password?');

        $this->createInfo['user']['databases'] = array();
        $this->createInfo['user']['databases'][] = $this->ask('What database you would like that user to access? [' . $dbName . ']', 'QUESTION', $dbName);

        $summary = array(
            ' ',
            'Name: ' . $this->createInfo['user']['name'],
            'Password: ' . $this->createInfo['user']['password'],
            'Database access: ' . implode(',', $this->createInfo['user']['databases']),
            '                                                 ',
            'Is this setup correct? [Y/n]',
            ' ',
        );

        if ($this->askConfirmation($summary)) {
            return true;
        } else {
            return $this->setupUser($username);
        }
    }

    /**
     * Generate an array of strings to log as a summary of the task
     * @return array
     */
    protected function getSummary()
    {
        $summary = array('                                       ');

        if (empty($this->instanceId)) {
            $summary[] = 'Creating new instance:';
            $summary[] = "Name: " . $this->createInfo['instance']['name'];
            $summary[] = "Size: " . $this->createInfo['instance']['volumeSize'];
            $summary[] = "Flavor: " . $this->createInfo['instance']['flavorId'];
        } else {
            $summary[] = 'Using existing instance: ' . $this->instanceId;
        }

        $summary[] = ' ';

        if (empty($this->databaseName)) {
            $summary[] = 'Creating new database:';
            $summary[] = "Name: " . $this->createInfo['database']['name'];
            $summary[] = "Charset: " . $this->createInfo['database']['character_set'];
            $summary[] = "Collate: " . $this->createInfo['database']['collate'];
        } else {
            $summary[] = 'Using existing database: ' . $this->databaseName;
        }

        $summary[] = ' ';

        if (empty($this->userName)) {
            $summary[] = 'Creating/updating user:';
            $summary[] = "Name: " . $this->createInfo['user']['name'];
            $summary[] = "Password: " . $this->createInfo['user']['password'];
            $summary[] = "Databases: " . implode(',', $this->createInfo['user']['databases']);
        } else {
            $summary[] = 'Using existing user: ' . $this->userName;
            $summary[] = ' ';
            $summary[] = 'NOTE: since you are using existing user who already has permission to database';
            $summary[] = 'We do not know the password, hence you would need to update the config by hand';
        }

        $summary[] = ' ';

        return $summary;
    }

    /**
     * Try to flush all relevant info to the rackspace.
     * Does nothing if we are using existing instance, db and user
     */
    protected function doSave()
    {
        // if we got no instance just go straight for the creation info
        if (empty($this->instanceId)) {
            $instance = new CloudInstance($this->createInfo['instance']);

            // lets see if we also creating a db and user
            $databases = (!isset($this->databaseName)) ? array(new CloudDatabase($this->createInfo['database'])) : $databases = array();
            $users = (empty($this->userName)) ? array(new CloudUser($this->createInfo['user'])) : $users = array();

            $this->savedInstance = $this->rackspacer->addInstance($instance, $databases, $users);

            if (empty($this->savedInstance->status)) {
                $this->logBlock('Could not retrieve status of created instance', 'ERROR');
                $this->end();
            } elseif ($this->savedInstance->status != 'BUILD') {
                $this->logBlock('New instance returned a weird status: ' . $this->savedInstance->status, 'ERROR');
                $this->end();
            } else {
                $this->logBlock('New instance created with status: ' . $this->savedInstance->status, 'INFO');
            }
        } else {
            // make sure we got a full instance going on
            if (empty($this->savedInstance)) {
                $this->savedInstance = $this->rackspacer->getInstance($this->instanceId);
            }

            // otherwise check if we creating a db and do it
            if (!isset($this->databaseName)) {
                $database = new CloudDatabase($this->createInfo['database']);
                $this->savedDatabase = $this->rackspacer->addDatabase($this->instanceId, $database);
                $this->logBlock('New database created', 'INFO');
            } elseif (empty($this->savedDatabase)) {
                $this->savedDatabase = new CloudDatabase();
            }

            // and then add/update user if requested
            if (!isset($this->userName)) {
                $user = new CloudUser($this->createInfo['user']);
                $this->savedUser = $this->rackspacer->addUser($this->instanceId, $user);
                $this->logBlock('User created/updated', 'INFO');
            } elseif (empty($this->savedUser)) {
                $this->savedUser = new CloudUser(array('name' => $this->userName));
            }
        }
    }

    protected function end()
    {
        $this->logSection('exit', 'KTHXBAI');
        exit;
    }

    /**
     * Get array representing database config. Doctrine version
     *
     * @param string $dbEnv
     * @return array
     */
    protected function getDoctrineConfigArray($dbEnv = 'all')
    {
        $config = array(
            $dbEnv => array(
                'doctrine' => array(
                    'class' => 'sfDoctrineDatabase',
                    'param' => array(
                        'dsn' => 'mysql:host=' . $this->savedInstance->hostname . ';dbname=' . $this->savedDatabase->name,
                        'username' => $this->savedUser->name,
                        'password' => ($this->savedUser->password) ? : 'CHANGE_ME',
                        'attributes' => array(
                            'use_native_enum' => true
                        )
                    )
                )
            )
        );

        if (isset($this->savedDatabase->characterSet)) {
            $config[$dbEnv]['doctrine']['param']['attributes']['default_table_charset'] = $this->savedDatabase->characterSet;
        }

        if (isset($this->savedDatabase->collate)) {
            $config[$dbEnv]['doctrine']['param']['attributes']['default_table_collate'] = $this->savedDatabase->collate;
        }

        return $config;
    }

    /**
     * Get array representing database config. Propel version
     *
     * @param string $dbEnv
     * @return array
     */
    protected function getPropelConfigArray($dbEnv = 'all')
    {
        $config = array(
            $dbEnv => array(
                'propel' => array(
                    'class' => 'sfPropelDatabase',
                    'param' => array(
                        'classname' => 'PropelPDO',
                        'dsn' => 'mysql:dbname=' . $this->savedDatabase->name . ';host=' . $this->savedInstance->hostname,
                        'username' => $this->savedUser->name,
                        'password' => ($this->savedUser->password) ? : 'CHANGE_ME',
                    )
                )
            )
        );

        if (isset($this->savedDatabase->characterSet)) {
            $config[$dbEnv]['propel']['param']['encoding'] = $this->savedDatabase->characterSet;
        }

        return $config;
    }

    /**
     * Attempt to save given string to databases.yml file in config directory
     *
     * @param string $configYml
     * @return int
     */
    protected function doConfigSave($configYml)
    {
        $configFilename = sfConfig::get('sf_config_dir') . DIRECTORY_SEPARATOR . 'databases.yml';
        $error = array();

        // check the dir
        if (!is_writable(sfConfig::get('sf_config_dir'))) {
            $error[] = sprintf('It seems that we cant write to your global config directory (%s).', sfConfig::get('sf_config_dir'));
            $error[] = 'Please add databases.yml yourself using the details provided.';
        } elseif (file_exists($configFilename)) { // we don't want to go into the hassle of overwriting the config
            $error[] = sprintf('It seems that you already have databases.yml in your global config directory (%s).', sfConfig::get('sf_config_dir'));
            $error[] = 'Please modify it yourself using the details provided.';
        } elseif ($this->askConfirmation(sprintf('Would you like to save that information into %s? [Y/n]', $configFilename), 'QUESTION', true)) {
            // if all passed and user agreed to save, just go for it
            $this->log('Saving as ' . $configFilename);

            if (file_put_contents($configFilename, $configYml) === false) {
                $error[] = 'There was an error during the file save.';
                $error[] = 'Please add databases.yml yourself using the details provided.';
            } else {
                $this->logBlock('All went well', 'INFO');
            }
        }

        // info errors if any
        if (!empty($error)) {
            array_unshift($error, ' ');
            $error[] = ' ';
            $this->logBlock($error, 'ERROR');

            return 0;
        }

        // because its overninethousand
        return 9001;
    }

}