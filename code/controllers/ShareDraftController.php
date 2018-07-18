<?php

class ShareDraftController extends Controller
{
    /**
     * Controller for rendering draft pages.
     *
     * @config
     *
     * @var string
     */
    private static $controller = 'Page_Controller';

    /**
     * @var array
     */
    private static $allowed_actions = array(
        'preview'
    );

    /**
     * @var array
     */
    private static $url_handlers = array(
        '$Key/$Token' => 'preview'
    );

    /**
     * @param SS_HTTPRequest $request
     *
     * @return string|HTMLText
     */
    public function preview(SS_HTTPRequest $request)
    {
        // Ensure this URL doesn't get picked up by HTTP caches
        HTTPCacheControl::singleton()->disableCache();

        $key = $request->param('Key');
        $token = $request->param('Token');

        /**
         * @var ShareToken $shareToken
         */
        $shareToken = ShareToken::get()->filter('Token', $token)->first();

        if (!$shareToken) {
            return $this->errorPage();
        }

        $page = Versioned::get_one_by_stage(
            'SiteTree',
            'Stage',
            sprintf('"SiteTree"."ID" = \'%d\'', $shareToken->PageID)
        );

        $latest = Versioned::get_latest_version('SiteTree', $shareToken->PageID);

        $controller = $this->getControllerFor($page);

        if (!$shareToken->isExpired() && $page->generateKey($shareToken->Token) === $key) {
            Requirements::css(SHAREDRAFTCONTENT_DIR . '/css/top-bar.css');

            // Temporarily un-secure the draft site and switch to draft
            $oldSecured = Versioned::get_draft_site_secured();
            $oldSecuredSession = Session::get('unsecuredDraftSite');
            $oldMode = Versioned::get_reading_mode();
            $restore = function () use ($oldSecured, $oldMode, $oldSecuredSession) {
                Versioned::set_reading_mode($oldMode);
                Versioned::set_default_reading_mode(null);
                Versioned::set_draft_site_secured($oldSecured);
                Session::set('unsecuredDraftSite', $oldSecuredSession);
            };

            // Process page inside an unsecured draft container
            try {
                Session::set('unsecuredDraftSite', true);
                Versioned::reading_stage('Stage');
                Versioned::set_default_reading_mode(Versioned::get_reading_mode());
                Versioned::set_draft_site_secured(false);

                // Hack to get around ContentController::init() redirecting on home page
                $_FILES = array(array());

                // Create mock request; Simplify request to single top level request
                $pageRequest = Injector::inst()->create('SS_HTTPRequest', 'GET', $page->URLSegment);

                // Extend to allow other modules to modify the request before it's processed
                $this->extend('updatePageRequest', $pageRequest, $page);

                $pageRequest->match('$URLSegment//$Action/$ID/$OtherID', true);
                $rendered = $controller->handleRequest($pageRequest, $this->model);

                // Render draft heading
                $data = new ArrayData(array(
                    'Page' => $page,
                    'Latest' => $latest,
                ));
                $include = (string) $data->renderWith('Includes/TopBar');
            } catch (Exception $ex) {
                $restore();
                throw $ex;
            }
            $restore();

            return str_replace('</body>', $include . '</body>', (string) $rendered->getBody());
        } else {
            return $this->errorPage();
        }
    }

    /**
     * @return HTMLText
     */
    protected function errorPage()
    {
        Requirements::css(SHAREDRAFTCONTENT_DIR . '/css/error-page.css');

        return $this->renderWith('ShareDraftContentError');
    }

    /**
     * @param mixed $page
     *
     * @return mixed
     */
    protected function getControllerFor($page)
    {
        return ModelAsController::controller_for($page);
    }
}
