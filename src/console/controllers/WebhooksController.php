<?php

namespace cadenzajon\stripecart\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\App;
use craft\helpers\Console;
use craft\helpers\UrlHelper;
use craft\stripe\Plugin as StripePlugin;
use Stripe\Exception\InvalidRequestException;
use yii\console\ExitCode;

/**
 * Manages the Stripe webhook subscription (one-time setup).
 */
class WebhooksController extends Controller
{
    /**
     * Everything the official plugin's CP "create webhook" button subscribes
     * to, plus checkout.session.completed for this plugin's orderCompleted event.
     */
    private const EVENTS = [
        'product.created', 'product.updated', 'product.deleted',
        'price.created', 'price.updated', 'price.deleted',
        'customer.subscription.created', 'customer.subscription.updated',
        'customer.subscription.paused', 'customer.subscription.resumed',
        'customer.subscription.pending_update_applied', 'customer.subscription.pending_update_expired',
        'customer.subscription.deleted',
        'customer.created', 'customer.updated', 'customer.deleted',
        'payment_method.attached', 'payment_method.automatically_updated',
        'payment_method.updated', 'payment_method.detached',
        'invoice.created', 'invoice.finalized', 'invoice.marked_uncollectible',
        'invoice.overdue', 'invoice.paid', 'invoice.payment_action_required',
        'invoice.payment_failed', 'invoice.payment_succeeded', 'invoice.updated',
        'invoice.voided', 'invoice.deleted',
        'checkout.session.completed',
    ];

    /**
     * Creates the webhook endpoint on Stripe and stores its ID and signing
     * secret where the official plugin expects them (.env and its webhook table).
     *
     * @param string|null $url The public endpoint URL. Defaults to this site's
     * /stripe/webhooks/handle, which is where the official plugin listens.
     */
    public function actionSubscribe(?string $url = null): int
    {
        $stripe = StripePlugin::getInstance();
        $url ??= UrlHelper::siteUrl('stripe/webhooks/handle');
        $record = $stripe->getWebhooks()->getWebhookRecord();
        $currentId = App::parseEnv($record->webhookId ?? '') ?: null;

        if ($currentId) {
            try {
                $stripe->getApi()->getClient()->webhookEndpoints->update($currentId, [
                    'url' => $url,
                    'enabled_events' => self::EVENTS,
                ]);
                $this->stdout("Updated {$currentId} at {$url}\n", Console::FG_GREEN);

                return ExitCode::OK;
            } catch (InvalidRequestException $e) {
                if ($e->getStripeCode() !== 'resource_missing') {
                    throw $e;
                }
                $this->stdout("Saved endpoint {$currentId} no longer exists; creating a replacement.\n", Console::FG_YELLOW);
            }
        }

        $endpoint = $stripe->getApi()->getClient()->webhookEndpoints->create([
            'url' => $url,
            'enabled_events' => self::EVENTS,
            'api_version' => $stripe->getApi()::STRIPE_API_VERSION,
        ]);

        // Mirror the official plugin's saveWebhookData(): secret and ID go to
        // .env when writable, with the record holding the env references.
        $config = Craft::$app->getConfig();

        try {
            $config->setDotEnvVar('STRIPE_WH_KEY', $endpoint->secret ?? '');
            $record->webhookSigningSecret = '$STRIPE_WH_KEY';
        } catch (\Throwable) {
            $record->webhookSigningSecret = $endpoint->secret;
        }

        try {
            $config->setDotEnvVar('STRIPE_WH_ID', $endpoint->id);
            $record->webhookId = '$STRIPE_WH_ID';
        } catch (\Throwable) {
            $record->webhookId = $endpoint->id;
        }

        if (!$record->save()) {
            $this->stderr("Endpoint created on Stripe, but saving it locally failed. Add these to the plugin's webhook settings manually:\n", Console::FG_RED);
            $this->stdout("id: {$endpoint->id}\nsecret: {$endpoint->secret}\n");

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Subscribed {$endpoint->id} at {$url}\n", Console::FG_GREEN);
        $this->stdout("Signing secret saved to .env as STRIPE_WH_KEY.\n");

        return ExitCode::OK;
    }

    /**
     * Lists webhook endpoints on the Stripe account; marks the one this site uses.
     */
    public function actionStatus(): int
    {
        $stripe = StripePlugin::getInstance();
        $endpoints = $stripe->getApi()->getClient()->webhookEndpoints->all(['limit' => 100])->data;

        if (!$endpoints) {
            $this->stdout("No webhook endpoints are configured on this Stripe account.\n");

            return ExitCode::OK;
        }

        $currentId = App::parseEnv($stripe->getWebhooks()->getWebhookRecord()->webhookId ?? '');
        foreach ($endpoints as $endpoint) {
            $marker = $endpoint->id === $currentId ? ' <- this site' : '';
            $this->stdout("{$endpoint->id}  {$endpoint->status}  {$endpoint->url}{$marker}\n");
        }

        return ExitCode::OK;
    }

    /**
     * Deletes a webhook endpoint from Stripe (defaults to this site's endpoint).
     */
    public function actionUnsubscribe(?string $endpointId = null): int
    {
        $stripe = StripePlugin::getInstance();
        $record = $stripe->getWebhooks()->getWebhookRecord();
        $endpointId ??= App::parseEnv($record->webhookId ?? '') ?: null;

        if (!$endpointId) {
            $this->stderr("No endpoint ID given and none is saved locally.\n", Console::FG_RED);

            return ExitCode::USAGE;
        }

        $stripe->getApi()->getClient()->webhookEndpoints->delete($endpointId);

        if (App::parseEnv($record->webhookId ?? '') === $endpointId && !$record->getIsNewRecord()) {
            $record->delete();
        }

        $this->stdout("Deleted {$endpointId}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
