<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class DocumentStorageRegistry
{
    public function forAdapter(string $adapter, DocumentStorage $current): DocumentStorage
    {
        if (! in_array($adapter, ['local', 'nextcloud'], true)) {
            throw new RuntimeException('Private storage adapter required');
        }
        if ($current->adapter() === $adapter) {
            return $current;
        }
        if ($adapter === 'nextcloud') {
            return new NextcloudStorage(app(Factory::class), (string) config('fp.nextcloud.endpoint'), (string) config('fp.nextcloud.user'), (string) config('fp.nextcloud.password'));
        }

        return new PrivateLocalStorage(Storage::build(['driver' => 'local', 'root' => storage_path('app/fp-private'), 'visibility' => 'private', 'throw' => true]));
    }
}
