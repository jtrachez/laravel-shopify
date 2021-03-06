<?php namespace OhMyBrew\ShopifyApp\Traits;

use OhMyBrew\ShopifyApp\Jobs\WebhookInstaller;
use OhMyBrew\ShopifyApp\Jobs\ScripttagInstaller;
use OhMyBrew\ShopifyApp\Facades\ShopifyApp;

trait AuthControllerTrait
{
    /**
     * Index route which displays the login page
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return view('shopify-app::auth.index');
    }

    /**
     * Authenticating a shop
     *
     * @return \Illuminate\Http\Response
     */
    public function authenticate()
    {
        // Grab the shop domain (uses session if redirected from middleware)
        $shopDomain = request('shop') ?: session('shop');
        if (!$shopDomain) {
            // Back to login, no shop
            return redirect()->route('login');
        }

        // Save shop domain to session
        session(['shopify_domain' => ShopifyApp::sanitizeShopDomain($shopDomain)]);
        session()->forget('shop');

        if (!request('code')) {
            // Handle a request without a code
            return $this->authenticationWithoutCode();
        } else {
            // Handle a request with a code
            return $this->authenticationWithCode();
        }
    }

    /**
     * Fires when there is no code on authentication
     *
     * @return \Illuminate\Http\Response
     */
    protected function authenticationWithoutCode()
    {
        // Setup an API instance
        $shopDomain = session('shopify_domain');
        $api = ShopifyApp::api();
        $api->setShop($shopDomain);

        // Grab the authentication URL
        $authUrl = $api->getAuthUrl(
            config('shopify-app.api_scopes'),
            url(config('shopify-app.api_redirect'))
        );

        // Do a fullpage redirect
        return view('shopify-app::auth.fullpage_redirect', [
            'authUrl' => $authUrl,
            'shopDomain' => $shopDomain
        ]);
    }

    /**
     * Fires when there is a code on authentication
     *
     * @return \Illuminate\Http\Response
     */
    protected function authenticationWithCode()
    {
        // Setup an API instance
        $shopDomain = session('shopify_domain');
        $api = ShopifyApp::api();
        $api->setShop($shopDomain);

        // Check if request is verified
        if (!$api->verifyRequest(request()->all())) {
            // Not valid, redirect to login and show the errors
            return redirect()->route('login')->with('error', 'Invalid signature');
        }

        // Save token to shop
        $shop = ShopifyApp::shop();
        $shop->shopify_token = $api->requestAccessToken(request('code'));
        $shop->save();

        // Install webhooks and scripttags
        $this->installWebhooks();
        $this->installScripttags();

        // Run after authenticate job
        $this->afterAuthenticateJob();

        // Go to homepage of app
        return redirect()->route('home');
    }

    /**
     * Installs webhooks (if any)
     *
     * @return void
     */
    protected function installWebhooks()
    {
        $webhooks = config('shopify-app.webhooks');
        if (sizeof($webhooks) > 0) {
            dispatch(
                new WebhookInstaller(ShopifyApp::shop(), $webhooks)
            );
        }
    }

    /**
     * Installs scripttags (if any)
     *
     * @return void
     */
    protected function installScripttags()
    {
        $scripttags = config('shopify-app.scripttags');
        if (sizeof($scripttags) > 0) {
            dispatch(
                new ScripttagInstaller(ShopifyApp::shop(), $scripttags)
            );
        }
    }

    /**
     * Runs a job after authentication if provided
     * 
     * @return bool
     */
    protected function afterAuthenticateJob()
    {
        $jobConfig = config('shopify-app.after_authenticate_job');
        if (empty($jobConfig) || !isset($jobConfig['job'])) {
            // Empty config or no job assigned
            return false;
        }

        // We have a job, pass the shop object to the contructor
        $job = new $jobConfig['job'](ShopifyApp::shop());
        if (isset($jobConfig['inline']) && $jobConfig['inline'] == true) {
            // Run this job immediately
            $job->handle();
        } else {
            // Run later
            dispatch($job);
        }

        return true;
    }
}
