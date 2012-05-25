<?php

/**
 * Various exceptions according to http://docs.rackspace.com/cdb/api/v1.0/cdb-devguide/content/DB_faults.html
 */

class CloudBadRequestException extends Exception{}
class CloudUnauthorizedException extends Exception {};
class CloudForbiddenException extends Exception {};
class CloudItemNotFoundException extends Exception {};
class CloudBadMethodException extends Exception {};
class CloudOverLimitException extends Exception {};
class CloudBadMediaTypeException extends Exception {};
class CloudUnprocessableEntityException extends Exception {};
class CloudInstanceFaultException extends Exception {};
class CloudNotImplementedException extends Exception {};
class CloudServiceUnavailableException extends Exception {};
