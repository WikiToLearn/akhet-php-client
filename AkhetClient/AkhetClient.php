<?php

namespace AkhetClient;

/**
 * AkhetClient is the client to control an Akhet Server
 *
 * @author Luca Toma
 */
class AkhetClient {

    private $api_version = "0.8";
    private $hostname;
    private $username;
    private $password;
    private $memcached_host = null;
    private $memcached_port = null;
    private $memcached_ttl = null;

    /**
     * Main constructor
     * @param string $hostname Akhet server hostname
     * @param string $username Akhet http username
     * @param string $password AKhet http username
     * @param string $protocol Protocol to Akhet Server, by default is "http", the other option is "https"
     */
    public function __construct($hostname, $username, $password, $protocol = "http") {
        $protocol_clean = strtolower($protocol);
        if ($protocol_clean != "http" && $protocol_clean != "https") {
            throw new \AkhetClient\Exceptions\InvalidProtocol("Invalid protocol. You can use only HTTP or HTTPS");
        }
        $this->hostname = $protocol_clean . "://" . $hostname;
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Enable memcache and set the server
     * @param string $memcached_host Memcache server hostname
     * @param int $memcached_port Memcache server port
     * @param int $memcached_ttl Memcache ttl for values
     */
    public function memcache($memcached_host, $memcached_port, $memcached_ttl = 30) {
        $this->memcached_host = $memcached_host;
        $this->memcached_port = $memcached_port;
        $this->memcached_ttl = $memcached_ttl;
    }

    /**
     * Make the API request to the server
     * @param string $uri API uri
     * @param string $method API method
     * @param mixed $data Data to add in the POST/GET
     * @param boolean $bypass_memcache Bypass memcache
     * @return mixed API call result
     * @throws Exception
     */
    public function makeAPIRequest($uri, $method = "GET", $data = null, $bypass_memcache = false) {
        if (!in_array($method, array('GET', 'POST'))) {
            throw new \AkhetClient\Exceptions\MethodNotSupported("Method not supported");
        }
        $memcache = false;
        if ($method == 'GET' && $this->memcached_host !== null && $this->memcached_port !== null) {
            $memcache_value = false;
            if ((!$bypass_memcache) && class_exists("Memcached")) {
                $memcache = new Memcached();
                $memcache->addServer($this->memcached_host, $this->memcached_port);
                $memcache_key = "AKHETCLIENT:" . $uri . sha1(serialize($data));
                $memcache_value = $memcache->get($memcache_key);
            }
            if ($memcache_value !== false) {
                return $memcache_value;
            }
        }

        $url = $this->hostname . "/" . $this->api_version . "/" . $uri;

        $data_string = json_encode($data);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ":" . $this->password);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string))
        );

        $response_raw = curl_exec($ch);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $return_data = null;
        switch ($curl_info['http_code']) {
            case '200':
                if ($response_raw === false) {
                    throw new \AkhetClient\Exceptions\ServerNotAvailable("The server is not available");
                }

                $response = json_decode($response_raw);
                if ($response->version != $this->api_version) {
                    throw new \AkhetClient\Exceptions\Version("Server API version not allowed");
                }
                if (isset($response->data) && isset($response->data->error)) {
                    throw new \AkhetClient\Exceptions\ServerSideError($response->data->error, $response->data->errorno);
                }
                if ($memcache !== false) {
                    $memcache->set($memcache_key, $response->data, time() + $this->memcached_ttl);
                }
                $return_data = $response->data;
                break;
            case 401:
                throw new \AkhetClient\Exceptions\Unauthorized("User not authorized");
                break;
            default:
                var_dump($response_raw);
                throw new \AkhetClient\Exceptions\InvalidHTTPStatus("Invalid HTTP status");
                break;
        }
        return $return_data;
    }

    public function hostInfo() {
        return $this->makeAPIRequest("hostinfo");
    }

    public function listImages() {
        return $this->makeAPIRequest("imageslocal");
    }

    public function listImagesOnline() {
        return $this->makeAPIRequest("imagesonline");
    }

    public function createInstance($config) {
        $data = array(
            "user" => null, // username in the akhet system
            "image" => null, // image to use
            "network" => null, // network profile
            "resource" => null, // resources flavor
            "enable_cuda" => null, // cuda support
            "env" => null, // env vars
            "notimeout" => null, // instance is persistent
            "shared" => null, // instance is shared
            "uid" => null, // uid of the user
            "gids" => null, // gids of the user
            "storages" => null, // storages directory
            "additional_ws" => null, // array of additional websockets
            "additional_http" => null, // array of additional http
            "user_label" => null, // user display name
        );

        foreach ($config as $key => $value) {
            if (array_key_exists($key, $data)) {
                $data[$key] = $value;
            } else {
                error_log(__CLASS__ . ":" . __FUNCTION__ . " > Non existing " . $key . " option");
            }
        }

        foreach ($data as $key => $value) {
            if (is_null($data[$key])) {
                unset($data[$key]);
            }
        }

        $result = $this->makeAPIRequest("instance", "POST", $data, true);
        return $result->token;
    }

    public function getInstanceInfo($token) {
        $data = array(
            "token" => $token,
        );
        return $this->makeAPIRequest("instance", "GET", $data, true);
    }

    public function getInstanceResolutionInfo($token) {
        $data = array(
            "token" => $token,
        );
        return $this->makeAPIRequest("instance-resolution", "GET", $data, true);
    }

    public function setInstanceResolution($token, $width, $height) {
        $data = array(
            "token" => $token,
            "width" => $width,
            "height" => $height,
        );
        return $this->makeAPIRequest("instance-resolution", "POST", $data, true);
    }

}
