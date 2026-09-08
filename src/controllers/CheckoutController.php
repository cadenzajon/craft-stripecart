<?php

namespace cadenzajon\stripecart\controllers;

use cadenzajon\stripecart\Plugin;
use cadenzajon\stripecart\services\Checkout;
use Craft;
use craft\stripe\Plugin as StripePlugin;
use craft\web\Controller;
use craft\web\View;
use yii\web\Response;

class CheckoutController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    /**
     * Sends the visitor to Stripe Checkout for the current cart.
     */
    public function actionIndex(): Response
    {
        $this->requirePostRequest();

        try {
            $url = Plugin::getInstance()->checkout->getCheckoutUrl();
        } catch (\RuntimeException $e) {
            if ($this->request->getAcceptsJson()) {
                return $this->asFailure($e->getMessage());
            }
            $this->setFailFlash($e->getMessage());

            return $this->redirectToPostedUrl();
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['redirect' => $url]);
        }

        return $this->redirect($url);
    }

    /**
     * Return landing after Stripe Checkout. Verifies the Checkout Session with
     * Stripe and only clears the cart once payment is confirmed, so an
     * unverified or forged visit cannot empty the cart or claim success.
     */
    public function actionSuccess(): Response
    {
        $sessionId = $this->request->getQueryParam('session_id');
        $paid = false;
        $ours = false;

        if (is_string($sessionId) && $sessionId !== '') {
            try {
                $session = StripePlugin::getInstance()->getApi()->getClient()
                    ->checkout->sessions->retrieve($sessionId);
                $paid = ($session->status ?? null) === 'complete'
                    && in_array($session->payment_status ?? null, ['paid', 'no_payment_required'], true);

                // Only this browser's own checkout carries the stored reference.
                $expected = Craft::$app->getSession()->get(Checkout::SESSION_REF_KEY);
                $ours = $paid
                    && is_string($expected) && $expected !== ''
                    && ($session->client_reference_id ?? null) === $expected;
            } catch (\Throwable $e) {
                Craft::warning("Could not verify checkout session $sessionId: {$e->getMessage()}", __METHOD__);
            }
        }

        // Clear the cart only for this browser's own confirmed checkout, and
        // consume the reference so it cannot be replayed.
        if ($ours) {
            Craft::$app->getSession()->remove(Checkout::SESSION_REF_KEY);
            Plugin::getInstance()->cart->clear();
        }

        $view = Craft::$app->getView();
        $template = Plugin::getInstance()->getSettings()->successTemplate;
        if ($template && !$view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            Craft::warning("Configured checkout success template does not exist: $template", __METHOD__);
            $template = null;
        }
        if (!$template) {
            $template = $view->doesTemplateExist('checkout/success', View::TEMPLATE_MODE_SITE)
                ? 'checkout/success'
                : '_stripe-cart/checkout/_success.twig';
        }

        return $this->renderTemplate($template, [
            'sessionId' => $sessionId,
            'paid' => $paid,
            'ours' => $ours,
        ], View::TEMPLATE_MODE_SITE);
    }
}
