<?php

namespace App\Services\Tracking;

use App\Models\Customer;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

final class CustomerTrackingLinks
{
    public function create(Customer $customer, int $days = 30): string
    {
        if ($days < 1 || $days > 90) {
            throw new InvalidArgumentException('Tracking link lifetime must be between 1 and 90 days.');
        }

        // Opaque, URL-safe encrypted customer reference; never expose the database ID.
        $access = rtrim(strtr(Crypt::encryptString((string) $customer->getKey()), '+/', '-_'), '=');

        // A relative signature avoids proxy/scheme mismatches on cPanel hosting.
        $path = URL::temporarySignedRoute('tracking.show', now()->addDays($days), [
            'access' => $access,
        ], absolute: false);

        return rtrim((string) config('app.url'), '/') . $path;
    }
}
