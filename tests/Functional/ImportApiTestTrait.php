<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Admin\AdminApiScopeEnum;
use App\Service\Admin\AdminApiTokenManager;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

trait ImportApiTestTrait
{
    private const string TINY_JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    /**
     * @param list<AdminApiScopeEnum>|null $scopes every scope by default
     */
    private function newToken(?array $scopes = null): string
    {
        return static::getContainer()->get(AdminApiTokenManager::class)->generate('188967649332428800', $scopes ?? AdminApiScopeEnum::cases())->token;
    }

    /**
     * @param array<string, string>                                                                          $fields
     * @param array<string, array{tmp_name: string, name: string, type: string, error: int, size: int}|null> $files
     *
     * @return array<string, mixed>
     */
    private function apiRequest(KernelBrowser $client, string $method, string $uri, ?string $token, array $fields = [], array $files = []): array
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        $client->request($method, $uri, $fields, array_filter($files), $server);

        $decoded = json_decode((string) $client->getResponse()->getContent(), true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{tmp_name: string, name: string, type: string, error: int, size: int}
     */
    private function pngFile(string $name = 'card.png'): array
    {
        return $this->tmpFile(file_get_contents(__DIR__ . '/../../public/images/default_card.png') ?: '', $name, 'image/png');
    }

    /**
     * @return array{tmp_name: string, name: string, type: string, error: int, size: int}
     */
    private function jpegFile(string $name = 'card.jpg'): array
    {
        return $this->tmpFile((string) base64_decode(self::TINY_JPEG, true), $name, 'image/jpeg');
    }

    /**
     * @return array{tmp_name: string, name: string, type: string, error: int, size: int}
     */
    private function tmpFile(string $content, string $name, string $type): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'import-api-');
        \assert(false !== $tmp);
        file_put_contents($tmp, $content);

        return ['tmp_name' => $tmp, 'name' => $name, 'type' => $type, 'error' => \UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
    }
}
