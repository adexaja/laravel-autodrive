<?php

namespace PulkitJalan\Google;

use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Session;
use PulkitJalan\Google\Exceptions\UnknownServiceException;

class Client
{
    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Google_Client
     */
    protected $client;

    /**
     * @var string
     */
    protected $token;
    /**
     * @var string
     */
    protected $fileToken;

    protected $tokenKey = "AdexAjaDriveAuto.Laravel";
    /**
     * @param array $config
     * @param string $userEmail
     */
    public function __construct(array $config, $userEmail = '')
    {
        $this->config = $config;

        // create an instance of the google client for OAuth2
        $this->client = new \Google_Client();

        // set application name
        $this->client->setApplicationName(array_get($config, 'application_name', ''));

        // set oauth2 configs
        $this->client->setClientId(array_get($config, 'client_id', ''));
        $this->client->setClientSecret(array_get($config, 'client_secret', ''));
        $this->client->setRedirectUri(array_get($config, 'redirect_uri', ''));
        $this->client->setScopes(array_get($config, 'scopes', []));
        $this->client->setAccessType(array_get($config, 'access_type', 'online'));
        $this->client->setApprovalPrompt(array_get($config, 'approval_prompt', 'auto'));

        // set developer key
        $this->client->setDeveloperKey(array_get($config, 'developer_key', ''));

        $this->fileToken = array_get($config, 'file_token', '');

        // auth for service account
        if (array_get($config, 'service.enable', false)) {
            $this->auth($userEmail);
        }

        if($this->fileToken) {
            if(file_exists($this->fileToken))
                $this->token = json_decode(file_get_contents($this->fileToken), true);
        } else {
            $this->token = Session::get($this->tokenKey);
        }

        if($this->token) {
            $this->client->setAccessToken($this->token);
        } else if($code = Input::get("code")) {
            $this->client->authenticate($code);
            $this->token = $this->client->getAccessToken();
            $this->client->setAccessToken($this->token);
        }
         else {
            // no available token
            if(!empty(array_get($config, 'redirect_uri', ''))) {
                // redirect for authorization
                // redirect($this->Client->createAuthUrl());
                echo "<div style='background: rgba(0, 177, 225, 0.15); padding: 10px 15px; float: left; width: 100%; border-left: 6px solid #00b1e1;'>";
                echo "  <h1 style='padding: 0px; margin: 0px; font-size: 24px; color: #0788ab; font-weight: bold;'>Pemberitahuan</h1>";
                echo "  <p style=''>Akun anda belum terintegrasi dengan sistem kami. Silahkan melakukan konfirmasi akun ketika ada jendela popup. Pastikan anda tidak memblokir jendela popup. Jika jendela popup belum muncul, anda bisa menekan ikon refresh.</p>";
                echo "</div>";
                ?>
                <script>
                    var w = 500;
                    var h = 400;
                    var left = (screen.width/2)-(w/2);
                    var top = (screen.height/2)-(h/2);
                    var win = window.open('<?= $this->client->createAuthUrl(); ?>', '_blank', ', width='+w+', height='+h+', top='+top+', left='+left+', toolbar=0,location=0,menubar=0,scrollbars=0, resizable=0, opyhistory=no');
                    win.focus();
                </script>
                <?
            }
        }
        // check to make sure access token is not expired
        if($this->client->isAccessTokenExpired() and $this->token) {
            $tokens = json_decode($this->token);
            $refreshToken = $tokens->refresh_token;
            $this->client->refreshToken($refreshToken);
            $this->token = $this->client->getAccessToken();
        }

        // save access token
        if(!$this->client->isAccessTokenExpired()) {
            if($this->fileToken) {
                // $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
                if(!file_exists(dirname($this->fileToken))) {
                    mkdir(dirname($this->fileToken), 0700, true);
                }
                file_put_contents($this->fileToken, json_encode($this->token));
            } else {
                Session::put($this->tokenKey, $this->token);
            }
        }
    }

    /**
     * Getter for the google client.
     *
     * @return \Google_Client
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Getter for the google service.
     *
     * @param string $service
     *
     * @throws \Exception
     *
     * @return \Google_Service
     */
    public function make($service)
    {
        $service = 'Google_Service_'.ucfirst($service);

        if (class_exists($service)) {
            $class = new \ReflectionClass($service);

            return $class->newInstance($this->client);
        }

        throw new UnknownServiceException($service);
    }

    /**
     * Setup correct auth method based on type.
     *
     * @param $userEmail
     * @return void
     */
    protected function auth($userEmail = '')
    {
        // see (and use) if user has set Credentials
        if ($this->useAssertCredentials($userEmail)) {
            return;
        }

        // fallback to compute engine or app engine
        $this->client->useApplicationDefaultCredentials();
    }

    /**
     * Determine and use credentials if user has set them.
     * @param $userEmail
     * @return bool used or not
     */
    protected function useAssertCredentials($userEmail = '')
    {
        $serviceJsonUrl = array_get($this->config, 'service.file', '');

        if (empty($serviceJsonUrl)) {
            return false;
        }

        $this->client->setAuthConfig($serviceJsonUrl);
        
        if ($userEmail) {
            $this->client->setSubject($userEmail);
        }

        return true;
    }

    /**
     * Magic call method.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @throws \BadMethodCallException
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (method_exists($this->client, $method)) {
            return call_user_func_array([$this->client, $method], $parameters);
        }

        throw new \BadMethodCallException(sprintf('Method [%s] does not exist.', $method));
    }
}
