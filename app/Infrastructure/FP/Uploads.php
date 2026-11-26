<?php

declare(strict_types=1);

namespace App\Infrastructure\FP;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class Uploads
{
    public function __construct(private DocumentStorage $storage, private SecureImage $images) {}

    /**
     * Documents are staged in private local storage. The human-readable
     * Nextcloud folder is created only after approval, when the NIP is known.
     */
    public function stage(array $files): array
    {
        if (\App\Application\FP\OnboardingRevision::enabled()) {
            if (array_diff(array_keys($files), ['KTP', 'IJAZAH'])) {
                throw new InvalidArgumentException('Only KTP and IJAZAH uploads are supported');
            }
            $files = array_filter($files, static fn ($file) => $file !== null);
        } elseif (array_keys($files) !== ['PROFILE_PHOTO', 'KTP', 'IJAZAH']) {
            throw new InvalidArgumentException('Three documents required');
        }
        $storage = $this->stagingStorage();
        $folder = (string) Str::uuid();
        $staged = [];
        try {
            foreach ($files as $type => $file) {
                $bytes = $file->get();
                if (! is_string($bytes)) {
                    throw new InvalidArgumentException('Upload unreadable');
                } $safe = $this->images->sanitize($bytes);
                $path = 'fp/'.$folder.'/'.Str::uuid().'.jpg';

                $staged[] = ['type' => $type, 'private_path' => $path, 'mime' => $safe['mime'], 'bytes' => $safe['size'], 'sha256' => $safe['sha256'], 'storage_adapter' => $storage->adapter()];
                $storage->put($path, $safe['bytes']);
            }

            return $staged;
        } catch (\Throwable $error) {
            $this->discard($staged);
            throw $error;
        }
    }

    public function discard(array $documents): void
    {
        $storage = $this->stagingStorage();
        foreach ($documents as $document) {
            try {
                $storage->delete($document['private_path']);
            } catch (\Throwable) {
                report(new \RuntimeException('Staged FP cleanup pending'));
            }
        }
    }

    private function stagingStorage(): DocumentStorage
    {
        return app(DocumentStorageRegistry::class)->forAdapter('local', $this->storage);
    }
}
