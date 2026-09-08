<?php

namespace cadenzajon\stripecart\controllers;

use cadenzajon\stripecart\exceptions\CartException;
use cadenzajon\stripecart\Plugin;
use craft\web\Controller;
use yii\web\Response;

class CartController extends Controller
{
    protected array|int|bool $allowAnonymous = true;

    public function actionAdd(): Response
    {
        $this->requirePostRequest();
        $productId = (int)$this->request->getRequiredBodyParam('productId');
        $qty = (int)$this->request->getBodyParam('qty', 1);

        try {
            $result = Plugin::getInstance()->cart->add($productId, $qty);
        } catch (CartException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->respond(
            $result['capped'] ? "Limited to {$result['qty']} available." : 'Added to cart.',
            $result['qty'],
            $result['capped'],
        );
    }

    public function actionUpdate(): Response
    {
        $this->requirePostRequest();
        $productId = (int)$this->request->getRequiredBodyParam('productId');
        $qty = (int)$this->request->getRequiredBodyParam('qty');

        try {
            $result = Plugin::getInstance()->cart->update($productId, $qty);
        } catch (CartException $e) {
            return $this->fail($e->getMessage());
        }

        return $this->respond(
            $result['capped'] ? "Limited to {$result['qty']} available." : 'Cart updated.',
            $result['qty'],
            $result['capped'],
        );
    }

    public function actionRemove(): Response
    {
        $this->requirePostRequest();
        $productId = (int)$this->request->getRequiredBodyParam('productId');

        Plugin::getInstance()->cart->remove($productId);

        return $this->respond('Removed from cart.');
    }

    public function actionClear(): Response
    {
        $this->requirePostRequest();
        Plugin::getInstance()->cart->clear();

        return $this->respond('Cart cleared.');
    }

    private function respond(string $message, ?int $qty = null, bool $capped = false): Response
    {
        if ($this->request->getAcceptsJson()) {
            $data = [
                'message' => $message,
                'count' => Plugin::getInstance()->cart->getCount(),
            ];
            if ($qty !== null) {
                $data['qty'] = $qty;
                $data['capped'] = $capped;
            }

            return $this->asJson($data);
        }

        $this->setSuccessFlash($message);

        return $this->redirectToPostedUrl();
    }

    private function fail(string $message): Response
    {
        if ($this->request->getAcceptsJson()) {
            return $this->asFailure($message, [
                'count' => Plugin::getInstance()->cart->getCount(),
            ]);
        }

        $this->setFailFlash($message);

        return $this->redirectToPostedUrl();
    }
}
