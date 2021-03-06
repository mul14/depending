<?php

/*
 * This file is part of the depending.in package.
 *
 * (c) depending.in 2013
 */

namespace app\Model;

use app\Parameter;

/**
 * ModelGithub
 *
 * @author depending.in Dev
 */
class ModelGithub extends ModelBase 
{
    const ACCESS_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    const AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    const API_URL = 'https://api.github.com/';
    protected $clientId = '460190b3cf77dd2305ec';
    protected $clientSecret = '4c34311721e46c38c342420b5d101dd5670f16f6';
    protected $scope = 'user:email,public_repo';
    protected $accessToken = '';
    protected $redirectUrl = '';

    /**
     * Constructor
     */
    public function __construct(Parameter $params) {
        $this->accessToken = $params->get('githubToken');
        $this->redirectUrl = $params->get('redirectUrl');
    }

    /**
     * Get user data
     */
    public function getUser() {
        if (empty($this->accessToken)) return false;

        $response = $this->getData(self::API_URL.'user', array('access_token' => $this->accessToken));

        if ($response->get('result') == false || strpos($response->get('body'),'login')===false) {
            return false;
        }

        // Parsing the body
        if (($user = json_decode($response->get('body'))) && empty($user)) {
            return false;
        }

        $user = (array) $user;
        $userData = new Parameter($user);
        $userData->set('username', $userData->get('login'));

        return $userData;
    }

    /**
     * Generic repos API
     *
     * @param string User
     * @param string Token
     * @param bool organizations flag
     * @return array
     */
    public function getGithubRepositories($user = '', $token = '', $isOrganization = false) {
        $apiUrl = self::API_URL.($isOrganization ? 'orgs/'.$user.'/repos' : 'user/repos');
        $response = $this->getData($apiUrl, array('access_token' => $this->accessToken));

        if ($response->get('result') == false || strpos($response->get('body'),'full_name')===false) {
            return array();
        }

        // Parsing the body
        if (($repos = json_decode($response->get('body'))) && empty($repos)) {
            return array();
        }

        $repos = (array) $repos;

        return $repos;
    }

    /**
     * Get repository data
     */
    public function getRepositories() {
        if (empty($this->accessToken)) return false;

        $orgsRepos = array();
        $userRepos = $this->getGithubRepositories();

        // Try to get related organizations repo
        $orgs = $this->getOrganizations();

        if ( ! empty($orgs)) {
            // Fetch all organizations repos
            foreach ($orgs as $org) {
                if (property_exists($org, 'login')) {
                     $organizationRepos = $this->getGithubRepositories($org->login,$this->accessToken,true);

                    if ( ! empty($organizationRepos)) {
                        $orgsRepos = array_merge($orgsRepos,$organizationRepos);
                    }
                }
            }
        }

        $reposData = new Parameter(array_merge($orgsRepos,$userRepos));

        return $reposData;
    }

    /**
     * Get related organizations
     *
     * @return Parameter
     */
    public function getOrganizations() {
        if (empty($this->accessToken)) return array();

        $response = $this->getData(self::API_URL.'user/orgs', array('access_token' => $this->accessToken));

        if ($response->get('result') == false || strpos($response->get('body'),'login')===false) {
            return array();
        }

        // Parsing the body
        if (($orgs = json_decode($response->get('body'))) && empty($orgs)) {
            return array();
        }

        $orgs = (array) $orgs;

        return new Parameter($orgs);
    }

    /**
     * Set Hook data
     * 
     * @param string Repo full name
     * @return bool
     */
    public function setHookData($repo) {
        if (empty($this->accessToken)) return false;

        $hook = array(
            'name' => 'web',
            'active' => true,
            'events' => array('push','pull_request'),
            'config' => array(
                'url' => 'http://depending.in/hook',
                'content_type' => 'json',
                'secret' => $this->clientSecret,
            )
        );

        $response = $this->postJsonData(self::API_URL.'repos/'.$repo.'/hooks?access_token='.$this->accessToken, json_encode($hook));

        if (strpos($response->get('result'),'Not Found') !== false || $response->get('head[http_code]',500,true) !== 201) {
            return false;
        }

        return true;
    }

    /**
     * Get Hook data
     * 
     * @param string Repo full name
     * @return Parameter
     */
    public function getHookData($repo) {
        if (empty($this->accessToken)) return false;

        $response = $this->getData(self::API_URL.'repos/'.$repo.'/hooks', array('access_token' => $this->accessToken));

        if ($response->get('result') == false || strpos($response->get('body'),'Not Found')!==false) {
            return false;
        }

        // Parsing the body
        if (($hooks = json_decode($response->get('body'))) && empty($hooks)) {
            return false;
        }

        $success = false;
        $hooks = (array) $hooks;

        foreach ($hooks as $hook) {
            if ($hook->name == 'web' && strpos($hook->config->url, 'depending.in') !== false) {
                $success = new Parameter((array) $hook);
                break;
            }
        }

        return $success;
    }

     /**
     * Remove Hook data
     * 
     * @param string Repo full name
     * @return bool
     */
    public function removeHookData($repo) {
        if (empty($this->accessToken)) return false;

       $response = $this->getData(self::API_URL.'repos/'.$repo.'/hooks', array('access_token' => $this->accessToken));

        if ($response->get('result') == false || strpos($response->get('body'),'Not Found')!==false) {
            return false;
        }

        // Parsing the body
        if (($hooks = json_decode($response->get('body'))) && empty($hooks)) {
            return false;
        }

        $success = false;
        $hooks = (array) $hooks;

        foreach ($hooks as $hook) {
            if ($hook->name == 'web' && strpos($hook->config->url, 'depending.in') !== false) {

                $deleteResponse = $this->removeData(self::API_URL.'repos/'.$repo.'/hooks/'.$hook->id.'?access_token='.$this->accessToken);

                $success = ($deleteResponse == 204);
                break;
            }
        }

        return $success;
    }

    /**
     * Retrieve access parameter from code
     * @param string Code
     */
    public function getAccessToken($code) {
        $params = array(
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        );

        $response = $this->postData(self::ACCESS_TOKEN_URL,$params);

        if (strpos($response->get('body'), 'access_token') !== false) {
            // Get access token and token type
            list($accessTokenQuery,$tokenTypeQuery) = explode('&', $response->get('body'));

            return str_replace('access_token=', '', $accessTokenQuery);
        } else {
            return false;
        }
    }

    /**
     * Build login url
     */
    public function getLoginUrl() {
        $params = array(
            'client_id' => $this->clientId,
            'scope' => $this->scope,
            'redirect_uri' => $this->redirectUrl,
        );

        return self::AUTHORIZE_URL.'?'.http_build_query($params);
    }

     /**
     * POST an API JSON data
     *
     * @param string $url
     * @param array $data
     * @return Parameter
     * @throws RuntimeException
     */
    public function postJsonData($url, $jsonData) {
         try {
            // Start output buffer
            ob_start();

            //open connection
            $request = curl_init();

            curl_setopt($request, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_setopt($request, CURLOPT_URL, $url);
            curl_setopt($request, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($request, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($request, CURLOPT_VERBOSE, TRUE);
            curl_setopt($request, CURLOPT_POSTFIELDS, $jsonData);
            curl_setopt($request, CURLOPT_POST, 1); 

            //execute post
            $result  = curl_exec($request);
            $err     = curl_errno($request); 
            $errmsg  = curl_error($request) ;
            $head    = curl_getinfo($request);

            //close connection
            curl_close($request);

            // Capture the buffer and assign into content holder
            $body = ob_get_clean();
        } catch (\Exception $e) {
            throw new \RuntimeException('cURL POST error');
        }

        return new Parameter(compact('result', 'err', 'errmsg', 'head', 'body'));
    }

    /**
     * POST an API data
     *
     * @param string $url
     * @param array $data
     * @return Parameter
     * @throws RuntimeException
     */
    public function postData($url, $data) {
         try {
            // Start output buffer
            ob_start();

            //open connection
            $request = curl_init();

            curl_setopt($request, CURLOPT_URL, $url);
            curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($data));

            //execute post
            $result  = curl_exec($request);
            $err     = curl_errno($request); 
            $errmsg  = curl_error($request) ;
            $head    = curl_getinfo($request);

            //close connection
            curl_close($request);

            // Capture the buffer and assign into content holder
            $body = ob_get_clean();
        } catch (\Exception $e) {
            throw new \RuntimeException('cURL POST error');
        }

        return new Parameter(compact('result', 'err', 'errmsg', 'head', 'body'));
    }

    /**
     * GET an API data
     *
     * @param string $url
     * @param array $data
     * @return Parameter
     * @throws Exception
     */
    public function getData($url, $data) {
        $url .= '?'.http_build_query($data);

        try {
            // Start output buffer
            ob_start();

            //open connection
            $request = curl_init();

            curl_setopt($request, CURLOPT_URL, $url);

            //execute post
            $result  = curl_exec($request);
            $err     = curl_errno($request); 
            $errmsg  = curl_error($request) ;
            $head    = curl_getinfo($request);

            //close connection
            curl_close($request);

            // Capture the buffer and assign into content holder
            $body = ob_get_clean();
        } catch (\Exception $e) {
            throw new \RuntimeException('cURL GET error');
        }

        return new Parameter(compact('result', 'err', 'errmsg', 'head', 'body'));
    }

     /**
     * DELETE an API data
     *
     * @param string $url
     * @return int HTTP Code
     * @throws Exception
     */
    public function removeData($url) {
        try {
            // Start output buffer
            ob_start();

            //open connection
            $request = curl_init();

            curl_setopt($request, CURLOPT_URL, $url);

            curl_setopt($request, CURLOPT_URL,$url);
            curl_setopt($request, CURLOPT_CUSTOMREQUEST, "DELETE");

            $result = curl_exec($request);
            $httpCode = curl_getinfo($request, CURLINFO_HTTP_CODE);

            //close connection
            curl_close($request);

            // Capture the buffer and assign into content holder
            $body = ob_get_clean();
        } catch (\Exception $e) {
            throw new \RuntimeException('cURL DELETE error');
        }

        return $httpCode;
    }
    
   
}