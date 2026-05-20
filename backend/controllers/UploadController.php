<?php
// backend/controllers/UploadController.php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\{Response, Request};
use App\Middleware\AuthMiddleware;
use App\Helpers\ImageStore;

/**
 * Image upload endpoint.
 *
 *   POST /api/uploads/image
 *     body: { "data_url": "data:image/jpeg;base64,...", "purpose": "order" }
 *     returns: { "data": { "path": "/uploads/orders/2026/05/<uuid>.jpg" } }
 *
 * Most of the actual work lives in App\Helpers\ImageStore so that other
 * controllers (TeamLeaderController::directBook, SyncController, etc.)
 * can defensively normalize any image_path field they receive.
 */
class UploadController
{
    public function image(array $params = []): void
    {
        AuthMiddleware::require(['admin', 'team_leader']);

        $req     = new Request();
        $dataUrl = (string)$req->input('data_url', '');
        $purpose = (string)$req->input('purpose', 'order');

        if (!$dataUrl) {
            Response::error('data_url is required', 422);
        }
        if (!str_starts_with($dataUrl, 'data:image/')) {
            Response::error('Expected a base64-encoded image data URL', 422);
        }

        $path = ImageStore::saveDataUrl($dataUrl, $purpose);

        if (!$path) {
            // Could be invalid MIME, too big, too small, magic-bytes mismatch,
            // or the uploads/ folder isn't writable.
            Response::error(
                'Could not save image. Check that it is JPG/PNG/WEBP ≤ 5 MB and that the uploads folder is writable.',
                422
            );
        }

        Response::success(['path' => $path], 'Uploaded');
    }
}
