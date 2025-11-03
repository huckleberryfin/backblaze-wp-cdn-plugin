<?php
if (!defined('ABSPATH')) exit;

class BB_Uploader {
    private $bucket;
    private $endpoint;
    private $cdn_url;
    private $key_id;
    private $app_key;

    public function __construct($bucket, $endpoint, $cdn_url, $key_id, $app_key) {
        $this->bucket = $bucket;
        $this->endpoint = $endpoint;
        $this->cdn_url = $cdn_url;
        $this->key_id = $key_id;
        $this->app_key = $app_key;
    }

    public function upload_file($file_path, $relative_path) {
        $file_contents = file_get_contents($file_path);
        $content_type = mime_content_type($file_path);

        // URL encode only the filename, not the path separators
        $path_parts = explode('/', $relative_path);
        $encoded_parts = array();
        foreach ($path_parts as $part) {
            $encoded_parts[] = rawurlencode($part);
        }
        $encoded_path = implode('/', $encoded_parts);

        $url = "https://{$this->bucket}.{$this->endpoint}/{$encoded_path}";
        $timestamp = gmdate('Ymd\THis\Z');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $file_contents);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: ' . $content_type,
            'x-amz-acl: public-read',
            'x-amz-content-sha256: UNSIGNED-PAYLOAD',
            'x-amz-date: ' . $timestamp,
            'Authorization: ' . $this->get_auth_header('PUT', $encoded_path, $content_type)
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return array(
            'success' => ($http_code == 200),
            'http_code' => $http_code,
            'response' => $response
        );
    }

    private function get_auth_header($method, $key, $content_type) {
        preg_match('/s3\.([^.]+)\./', $this->endpoint, $matches);
        $region = isset($matches[1]) ? $matches[1] : 'us-east-005';
        $service = 's3';

        $timestamp = gmdate('Ymd\THis\Z');
        $datestamp = gmdate('Ymd');

        $canonical_uri = '/' . $key;
        $canonical_querystring = '';
        $canonical_headers = "host:{$this->bucket}.{$this->endpoint}\nx-amz-acl:public-read\nx-amz-content-sha256:UNSIGNED-PAYLOAD\nx-amz-date:{$timestamp}\n";
        $signed_headers = 'host;x-amz-acl;x-amz-content-sha256;x-amz-date';

        $canonical_request = "{$method}\n{$canonical_uri}\n{$canonical_querystring}\n{$canonical_headers}\n{$signed_headers}\nUNSIGNED-PAYLOAD";

        $algorithm = 'AWS4-HMAC-SHA256';
        $credential_scope = "{$datestamp}/{$region}/{$service}/aws4_request";
        $string_to_sign = "{$algorithm}\n{$timestamp}\n{$credential_scope}\n" . hash('sha256', $canonical_request);

        $kDate = hash_hmac('sha256', $datestamp, 'AWS4' . $this->app_key, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $string_to_sign, $kSigning);

        return "{$algorithm} Credential={$this->key_id}/{$credential_scope}, SignedHeaders={$signed_headers}, Signature={$signature}";
    }

    public function get_cdn_url() {
        return $this->cdn_url;
    }
}
