<?php

namespace Michaelrk02\OmPhp;

use CURLFile;
use Exception;
use stdClass;

/**
 * Object manager client class
 */
class Client
{
    /**
     * @var string $serverUrl
     */
    protected $serverUrl;

    /**
     * @var string $secretKey
     */
    protected $secretKey;

    /**
     * Construct a new object manager client object
     *
     * @param string $serverUrl Object manager server URL
     * @param string $secretKey Secret key used for request authenticity
     */
    public function __construct($serverUrl, $secretKey)
    {
        $this->serverUrl = $serverUrl;
        $this->secretKey = $secretKey;
    }

    /**
     * Upload a file and store it as an object with optional attributes
     *
     * Attributes can consist of:
     *
     * - `access` : access level (either 'public' or 'protected'), server typically defaults to protected level unless configured otherwise
     * - `mime_type` : override MIME type of the file
     * - `cache_age` : cache age (in seconds) if the object is public
     * - `ttl` : object URL expiration time (in seconds) if the object is protected
     *
     * @param string $collection Name of the object collection (only lowercase alphanumeric characters, underscores, and dashes are allowed)
     * @param string $filePath Path the of file to upload
     * @param array $attributes Object attributes
     *
     * @return string|bool Object ID on success or `false` on failure
     */
    public function store($collection, $filePath, $attributes = [])
    {
        if ($collection === '') {
            throw new Exception('collection name cannot be empty');
        }
        if (preg_match('/^[a-z0-9_-]+$/', $collection) !== 1) {
            throw new Exception('invalid collection name format');
        }

        $attributes = count($attributes) == 0 ? new stdClass() : $attributes;

        $params = [];
        $params['time'] = time();
        $params['collection'] = $collection;
        $params['file'] = new CURLFile($filePath);
        $params['attributes'] = json_encode($attributes);
        $params['signature'] = hash_hmac('sha256', $params['time'].$params['collection'].md5_file($filePath).md5($params['attributes']), $this->secretKey);

        $request = curl_init($this->serverUrl.'store.php');
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, $params);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);

        $response = @json_decode(curl_exec($request), true);

        curl_close($request);

        return isset($response) && array_key_exists('object_id', $response) ? $response['object_id'] : false;
    }

    /**
     * Delete an object
     *
     * @param string $objectId Object ID to delete
     *
     * @return bool `true` on success, `false` on failure
     */
    public function delete($objectId)
    {
        $params = [];
        $params['time'] = time();
        $params['id'] = $objectId;
        $params['signature'] = hash_hmac('sha256', $params['time'].$params['id'], $this->secretKey);

        $request = curl_init($this->serverUrl.'delete.php');
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
        curl_exec($request);

        $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        curl_close($request);

        return $status === 200;
    }

    /**
     * Get the accessible URL of an object
     *
     * @param string $objectId Object ID
     *
     * @return string|bool URL of the object on success or `false` on failure
     */
    public function getUrl($objectId)
    {
        $params = [];
        $params['time'] = time();
        $params['id'] = $objectId;
        $params['signature'] = hash_hmac('sha256', $params['time'].$params['id'], $this->secretKey);

        $request = curl_init($this->serverUrl.'url.php?'.http_build_query($params));
        curl_setopt($request, CURLOPT_HEADER, false);
        curl_setopt($request, CURLOPT_RETURNTRANSFER, true);

        $response = @json_decode(curl_exec($request), true);

        curl_close($request);

        return isset($response) && array_key_exists('object_url', $response) ? $response['object_url'] : false;
    }

    /**
     * Stream an object
     *
     * This will immediately redirect the request to the object manager server
     *
     * @param string $objectId Object ID
     */
    public function stream($objectId)
    {
        $objectUrl = $this->getUrl($objectId);
        if ($objectUrl === false) {
            http_response_code(500);
            exit;
        }

        http_response_code(301);
        header('Location: '.$objectUrl);
        exit;
    }

    /**
     * Download an object and store it to the file system
     *
     * @param string $objectId Object ID to download
     * @param string|null $destinationPath Path to store the file (`null` to store to a temporary file)
     *
     * @return string|false Path to file or `false` on failure
     */
    public function fetch($objectId, $destinationPath = null)
    {
        $objectUrl = $this->getUrl($objectId);
        if ($objectUrl === false) {
            return false;
        }

        $destinationPath = isset($destinationPath) ? $destinationPath : tempnam(sys_get_temp_dir(), 'omphp');
        $fp = fopen($destinationPath, 'w');
        if ($fp === false) {
            return false;
        }

        $request = curl_init($objectUrl);
        curl_setopt($request, CURLOPT_FILE, $fp);
        curl_exec($request);

        fflush($fp);
        fclose($fp);

        $status = (int)curl_getinfo($request, CURLINFO_RESPONSE_CODE);
        if ($status !== 200) {
            unlink($destinationPath);
            curl_close($request);
            return false;
        }

        curl_close($request);

        return $destinationPath;
    }
}
