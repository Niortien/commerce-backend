<?php

namespace App\Services;

use Cloudinary\Cloudinary;

class CloudinaryService
{
    private ?Cloudinary $cloudinary = null;

    private function client(): Cloudinary
    {
        if ($this->cloudinary === null) {
            $this->cloudinary = new Cloudinary([
                'cloud' => [
                    'cloud_name' => config('cloudinary.cloud_name'),
                    'api_key'    => config('cloudinary.api_key'),
                    'api_secret' => config('cloudinary.api_secret'),
                ],
                'url' => ['secure' => true],
            ]);
        }
        return $this->cloudinary;
    }

    public function uploadFile(string $filePath, string $folder = 'produits'): string
    {
        try {
            $result = $this->client()->uploadApi()->upload($filePath, ['folder' => $folder, 'resource_type' => 'image']);
            return $result['secure_url'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cloudinary upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uploadBase64(string $dataUrl, string $folder = 'produits'): string
    {
        try {
            $result = $this->client()->uploadApi()->upload($dataUrl, ['folder' => $folder, 'resource_type' => 'image']);
            return $result['secure_url'];
        } catch (\Throwable $e) {
            throw new \RuntimeException('Cloudinary upload failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deleteByUrl(string $url): void
    {
        if (preg_match('/\/upload\/(?:v\d+\/)?(.+)\.[a-z]+$/i', $url, $matches)) {
            try {
                $this->client()->uploadApi()->destroy($matches[1]);
            } catch (\Throwable) {
                // Suppression non bloquante
            }
        }
    }
}
