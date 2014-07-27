<?php

/**
 * This file is part of the ProxmoxVE PHP API wrapper library (unofficial).
 *
 * @copyright 2014 César Muñoz <zzantares@gmail.com>
 * @license http://opensource.org/licenses/MIT The MIT License.
 */

namespace ProxmoxVE;

use ProxmoxVE\Exception\MalformedCredentialsException;
use ProxmoxVE\Exception\AuthenticationException;

/**
 * ProxmoxVE class. In order to interact with the proxmox server, the desired
 * app's code needs to create and use an object of this class.
 *
 * @author César Muñoz <zzantares@gmail.com>
 */
class Proxmox extends ProxmoxVE
{
    /**
     * The object that contains proxmox server authentication data.
     *
     * @var \ProxmoxVE\Credentials
     */
    private $credentials;


    /**
     * Holds the value of the base API URL, by default response is in JSON.
     * Sample value: https://my-proxmox:8006/api2/json
     *
     * @var string
     */
    private $apiUrl;


    /**
     * Holds the response type used to requests the API, possible values are
     * json, extjs, html, text, png.
     *
     * @var string
     */
    private $responseType;


    /**
     * Holds the fake response type, it is useful when you want to get the JSON
     * raw string instead of a PHP array.
     *
     * @var string
     */
    private $fakeType;


    public function parseCustomCredentials($credentials)
    {
        if (!is_object($credentials)) {
            return null;
        }

        // Trying to find variables
        $objectProperties = array_keys(get_object_vars($credentials));
        $requiredProperties = ['hostname', 'username', 'password'];

        // Needed properties exists in the object?
        $found = count(array_intersect($requiredProperties, $objectProperties));
        if ($found == count($requiredProperties)) {
            $realm = $credentials->realm;
            $port = $credentials->port;

            return [
                'hostname' => $credentials->hostname,
                'username' => $credentials->username,
                'password' => $credentials->password,
                'realm' => isset($realm) ? $realm : 'pam',
                'port' => isset($port) ? $port : '8006',
            ];
        }


        // Trying to find getters
        $objectMethods = get_class_methods($credentials);
        $requiredMethods = ['getHostname', 'getUsername', 'getPassword'];

        // Needed functions exists in the object?
        $found = count(array_intersect($requiredMethods, $objectMethods));
        if ($found == count($requiredMethods)) {
            $realm = method_exists($credentials, 'getRealm')
                ? $credentials->getRealm()
                : 'pam';

            $port = method_exists($credentials, 'getPort')
                ? $credentials->getPort()
                : '8006';

            return [
                'hostname' => $credentials->getHostname(),
                'username' => $credentials->getUsername(),
                'password' => $credentials->getPassword(),
                'realm' => $realm,
                'port' => $port,
            ];
        }

        // Get properties of object using magic method __get
        if (in_array('__get', $objectMethods)) {
            $hasHostname = $credentials->hostname;
            $hasUsername = $credentials->username;
            $hasPassword = $credentials->password;

            if ($hasHostname and $hasUsername and $hasPassword) {
                return [
                    'hostname' => $credentials->hostname,
                    'username' => $credentials->username,
                    'password' => $credentials->password,
                    'realm' => $credentials->realm ?: 'pam',
                    'port' => $credentials->port ?: '8006',
                ];
            }
        }
    }


    private function login()
    {
        $client = new \GuzzleHttp\Client();
        $url = "https://{$this->credentials['hostname']}:{$this->credentials['port']}/api2/json";
        $response = $client->post($url . '/access/ticket', [
            'verify' => false,
            'exceptions' => false,
            'body' => [
                'username' => $this->credentials['username'],
                'password' => $this->credentials['password'],
                'realm' => $this->credentials['realm'],
            ],
        ]);

        $response = $response->json();

        if (!$response['data']) {
            $error = 'Login "' . $this->credentials['username'] . '" failed!';
            throw new AuthenticationException($error);
        }

        return new AuthToken(
            $response['data']['CSRFPreventionToken'],
            $response['data']['ticket'],
            $response['data']['username']
        );
    }


    /**
     * Constructor.
     *
     * @param mixed $credentials Credentials object or associative array holding
     *                           the login data.
     *
     * @throws \InvalidArgumentException If bad args supplied.
     */
    public function __construct($credentials, $responseType = 'array')
    {
        $this->setCredentials($credentials);
        $this->token = $this->login();
    }


    /**
     * Returns the Credentials object associated with this proxmox API instance.
     * 
     * @return \ProxmoxVE\Credentials Object containing all proxmox data used to
     *                                connect to the server.
     */
    public function getCredentials()
    {
        return $this->credentials;
    }


    /**
     * Assign the passed Credentials object to the ProxmoxVE.
     *
     * @param object $credentials A custom object holding credentials or a
     *                            Credentials object to assign.
     */
    public function setCredentials($credentials)
    {
        if (!is_array($credentials)) {
            $credentials = $this->parseCustomCredentials($credentials);

            if (!$credentials) {
                $errorMessage = 'PVE API needs a credentials object or array.';
                throw new MalformedCredentialsException($errorMessage);
            }

        } else {
            $requiredKeys = ['hostname', 'username', 'password'];
            $credentialsKeys = array_keys($credentials);

            $found = count(array_intersect($requiredKeys, $credentialsKeys));

            if ($found != count($requiredKeys)) {
                $error = 'PVE credentials needs ' . implode(', ', $requiredKeys);
                throw new MalformedCredentialsException($error);
            }

            // Set default realm and port if are not in the array.
            if (!isset($credentials['realm'])) {
                $credentials['realm'] = 'pam';
            }

            if (!isset($credentials['port'])) {
                $credentials['port'] = '8006';
            }
        }

        $this->credentials = $credentials;
    }


    /**
     * Sets the response type that is going to be returned when doing requests.
     *
     * @param string $responseType One of json, html, extjs, text, png.
     */
    public function setResponseType($responseType = 'array')
    {
        $supportedFormats = array('json', 'html', 'extjs', 'text', 'png');

        if (!in_array($responseType, $supportedFormats)) {
            if ($responseType == 'pngb64') {
                $this->fakeType = 'pngb64';
                $this->responseType = 'png';
                return;
            }

            $this->responseType = 'json';

            if ($responseType == 'object') {
                $this->fakeType = $responseType;
            } else {
                $this->fakeType = 'array';  // Default format
            }

            return;
        }

        $this->fakeType = false;
        $this->responseType = $responseType;
    }


    /**
     * Returns the response type that is being used by the Proxmox API client.
     *
     * @return string Response type being used.
     */
    public function getResponseType()
    {
        return $this->fakeType ?: $this->responseType;
    }




    /**
     * GET a resource defined in the pvesh tool.
     *
     * @param string $actionPath The resource tree path you want to ask for, see
     *                           more at http://pve.proxmox.com/pve2-api-doc/
     * @param array $params      An associative array filled with params.
     *
     * @return array             A PHP array json_decode($response, true).
     *
     * @throws \InvalidArgumentException
     */
    public function get($actionPath, $params = array())
    {
        if (!is_array($params)) {
            $errorMessage = 'GET params should be an associative array.';
            throw new \InvalidArgumentException($errorMessage);
        }

        // Check if we have a prefixed '/' on the path, if not add one.
        if (substr($actionPath, 0, 1) != '/') {
            $actionPath = '/' . $actionPath;
        }

        $url = $this->apiUrl . $actionPath;

        return $this->processResponse(parent::get($url, $params));
    }


    /**
     * SET a resource defined in the pvesh tool.
     *
     * @param string $actionPath The resource tree path you want to ask for, see
     *                           more at http://pve.proxmox.com/pve2-api-doc/
     * @param array $params      An associative array filled with params.
     *
     * @return array             A PHP array json_decode($response, true).
     *
     * @throws \InvalidArgumentException
     */
    public function set($actionPath, $params = array())
    {
        if (!is_array($params)) {
            $errorMessage = 'PUT params should be an associative array.';
            throw new \InvalidArgumentException($errorMessage);
        }

        // Check if we have a prefixed '/' on the path, if not add one.
        if (substr($actionPath, 0, 1) != '/') {
            $actionPath = '/' . $actionPath;
        }

        $url = $this->apiUrl . $actionPath;

        return $this->processResponse(parent::put($url, $params));
    }


    /**
     * CREATE a resource as defined by the pvesh tool.
     *
     * @param string $actionPath The resource tree path you want to ask for, see
     *                           more at http://pve.proxmox.com/pve2-api-doc/
     * @param array $params      An associative array filled with POST params
     *
     * @return array             A PHP array json_decode($response, true).
     *
     * @throws \InvalidArgumentException
     */
    public function create($actionPath, $params = array())
    {
        if (!is_array($params)) {
            $errorMessage = 'POST params should be an asociative array.';
            throw new \InvalidArgumentException($errorMessage);
        }

        // Check if we have a prefixed '/' on the path, if not add one.
        if (substr($actionPath, 0, 1) != '/') {
            $actionPath = '/' . $actionPath;
        }

        $url = $this->apiUrl . $actionPath;

        return $this->processResponse(parent::post($url, $params));
    }


    /**
     * DELETE a resource defined in the pvesh tool.
     *
     * @param string $actionPath The resource tree path you want to ask for, see
     *                           more at http://pve.proxmox.com/pve2-api-doc/
     * @param array $params      An associative array filled with params.
     *
     * @return array             A PHP array json_decode($response, true).
     *
     * @throws \InvalidArgumentException
     */
    public function delete($actionPath, $params = array())
    {
        if (!is_array($params)) {
            $errorMessage = 'DELETE params should be an associative array.';
            throw new \InvalidArgumentException($errorMessage);
        }

        // Check if we have a prefixed '/' on the path, if not add one.
        if (substr($actionPath, 0, 1) != '/') {
            $actionPath = '/' . $actionPath;
        }

        $url = $this->apiUrl . $actionPath;

        return $this->processResponse(parent::delete($url, $params));
    }


    // Later on below this line we'll move this logic to another place?


    /**
     * Returns the proxmox API URL where requests are sended.
     *
     * @return string Proxmox API URL.
     */
    public function getApiUrl()
    {
        return 'https://' . $this->credentials->getHostname() . ':'
            . $this->credentials->getPort() . '/api2/' . $this->responseType;
    }


    /**
     * Parses the response to the desired return type.
     *
     * @param string $response Response sended by the Proxmox server.
     *
     * @return mixed The parsed response, depending on the response type can be
     *               an array or a string.
     */
    public function processResponse($response)
    {
        if ($this->fakeType) {
            if ($this->fakeType == 'pngb64') {
                $base64 = base64_encode($response);
                return 'data:image/png;base64,' . $base64;
            }

            // For now 'object' is not supported, so we return array by default.
            return json_decode($response, true);
            // Later on need to add a check to see if is 'array' or 'object'
        }

        // Other types of response doesn't need any treatment
        return $response;
    }


    /**
     * Attempts to validate an object to see if can be used as a credentials
     * provider. This is helpful in the case you have an Eloquent model that
     * already acts as a credentials object.
     *
     * @param object $credentials Object with accessible properties or getters.
     *
     * @return bool false If the object can't be used as a credentials provider.
     */
    public function validCredentialsObject($credentials)
    {
        if (!is_object($credentials)) {
            $this->accessibleBy = false;
            return false;
        }

        // Trying to find variables
        $vars = array_keys(get_object_vars($credentials));
        $properties = array(
            'hostname',
            'username',
            'password',
        );

        // Needed properties exists in the object?
        $found = count(array_intersect($properties, $vars));
        if ($found == count($properties)) {
            $this->accessibleBy = 'properties';
            return true;
        }

        // Trying to find getters
        $methods = get_class_methods($credentials);
        $functions = array(
            'getHostname',
            'getUsername',
            'getPassword',
        );

        // Needed functions exists in the object?
        $found = count(array_intersect($functions, $methods));
        if ($found == count($functions)) {
            $this->accessibleBy = 'methods';
            return true;
        }
        // Find properties that are using magic function

        $hasHostname = isset($credentials->hostname);
        $hasUsername = isset($credentials->username);
        $hasPassword = isset($credentials->password);

        if ($hasHostname and $hasUsername and $hasPassword) {
            $this->accessibleBy = '__get';
            return true;
        }

        $this->accessibleBy = false;
        return false;
    }


    /**
     * When a custom object is used as a credentials object this function will
     * attempt to login to the Proxmox server. Later on, logic will be rewritten
     * to not depend on the Credentials class.
     *
     * @param object $credentials A custom object holding proxmox login data.
     */
    protected function loginUsingCredentials($credentials)
    {

        if ($this->accessibleBy == 'properties') {
            return new Credentials(
                $credentials->hostname,
                $credentials->username,
                $credentials->password,
                isset($credentials->realm) ? $credentials->realm : 'pam',
                isset($credentials->port) ? $credentials->port : '8006'
            );
        }

        // In eloquent models properties not set are emppty thus not null
        if ($this->accessibleBy == '__get') {
            return new Credentials(
                $credentials->hostname,
                $credentials->username,
                $credentials->password,
                empty($credentials->realm) ? 'pam' : $credentials->realm,
                empty($credentials->port) ? '8006' : $credentials->port
            );
        }

        if ($this->accessibleBy == 'methods') {
            if (method_exists($credentials, 'getRealm')) {
                $realm = $credentials->getRealm();
            } else {
                $realm = 'pam';
            }

            if (method_exists($credentials, 'getPort')) {
            } else {
                $port = $credentials->getPort();
                $port = '8006';
            }

            return new Credentials(
                $credentials->getHostname(),
                $credentials->getUsername(),
                $credentials->getPassword(),
                $realm,
                $port
            );
        }

        /**
         * Maybe we need to implement this type of accesor?
         * $credentials->get('hostname');
         */

        // At this point this code can't be executed so ...
        //$error = "This can't happen, run in circles or do something else.";

        //throw new \RuntimeException($error);
    }


    /**
     * Retrieves the '/access' resource of the Proxmox API resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getAccess()
    {
        return $this->get('/access');
    }


    /**
     * Retrieves the '/cluster' resource of the Proxmox API resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getCluster()
    {
        return $this->get('/cluster');
    }


    /**
     * Retrieves the '/nodes' resource of the Proxmox API resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getNodes()
    {
        return $this->get('/nodes');
    }


    /**
     * Retrieves a specific node defined in the resources tree as '/nodes/node'.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getNode($nodeName)
    {
        return $this->get('/nodes/' . $nodeName);
    }


    /**
     * Retrieves the '/pools' resource of the Proxmox API resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getPools()
    {
        return $this->get('/pools');
    }


    /**
     * Retrieves a specific pool defined in the resources tree as '/pools/pool'.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getPool($poolId)
    {
        return $this->get('/pools/' . $poolId);
    }


    /**
     * Creates a pool resource inside the '/pools' resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function createPool($poolData)
    {
        if (!is_array($poolData)) {
            throw new \InvalidArgumentException('Pool data needs to be array');
        }

        return $this->create('/pools', $poolData);
    }


    /**
     * Sets the passed data in the specified pool (updates the pool info).
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function setPool($poolId, $poolData = array())
    {
        if (!is_array($poolData)) {
            throw new \InvalidArgumentException('Pool data needs to be array');
        }

        return $this->set('/pools/' . $poolId, $poolData);
    }


    /**
     * Deletes the specified pool from the '/pools' resources tree.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function deletePool($poolId)
    {
        return $this->delete('/pools/' . $poolId);
    }


    /**
     * Retrieves all the storages found in the Proxmox server, or only the ones
     * matching the storage type provided if any.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getStorages($type = null)
    {
        if (!$type) {
            return $this->get('/storage');
        }

        $supportedTypes = array(
            'lvm',
            'nfs',
            'dir',
            'zfs',
            'rbd',
            'iscsi',
            'sheepdog',
            'glusterfs',
            'iscsidirect',
        );

        if (in_array($type, $supportedTypes)) {
            return $this->get('/storage', array(
                'type' => $type,
            ));
        }

        /* If type not found returns null */
    }


    /**
     * Creates a storage resource using the passed data.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function createStorage($storageData)
    {
        if (!is_array($storageData)) {
            $errorMessage = 'Storage data needs to be array';
            throw new \InvalidArgumentException($errorMessage);
        }

        /* Should we check the required keys (storage, type) in the array? */

        return $this->create('/storage', $storageData);
    }


    /**
     * Retrieves a specified storage matching the passed storage identifier.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function getStorage($storageId)
    {
        return $this->get('/storage/' . $storageId);
    }


    /**
     * Sets the passed data into the specified storage (updates the storage
     * info).
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function setStorage($storageId, $storageData = array())
    {
        if (!is_array($storageData)) {
            $errorMessage = 'Storage data needs to be array';
            throw new \InvalidArgumentException($errorMessage);
        }

        return $this->set('/storage/' . $storageId, $storageData);
    }


    /**
     * Deletes the storage from the '/storage' resources tree that matches the
     * passed storage identifier.
     *
     * @return mixed The processed response, can be an array, string or object.
     */
    public function deleteStorage($storageId)
    {
        return $this->delete('/storage/' . $storageId);
    }

}

