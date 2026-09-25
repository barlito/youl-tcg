<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\Notification\InternalLinkPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RequestContext;

final class InternalLinkPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function acceptedLinks(): iterable
    {
        yield 'path' => ['/boosters', '/boosters'];
        yield 'path with query and fragment' => ['/univers/cosmos?tab=1#set', '/univers/cosmos?tab=1#set'];
        yield 'root' => ['/', '/'];
        yield 'absolute URL on the app host' => ['https://ytcg.example/classement', '/classement'];
        yield 'app host, any case, query kept' => ['HTTPS://YTCG.example/boosters?code=AB', '/boosters?code=AB'];
        yield 'app host without path' => ['https://ytcg.example', '/'];
        yield 'surrounding spaces trimmed' => ['  /collection  ', '/collection'];
    }

    #[DataProvider('acceptedLinks')]
    public function testInternalLinksAreReducedToAPath(string $link, string $expected): void
    {
        $this->assertSame($expected, $this->policy()->toInternalPath($link));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedLinks(): iterable
    {
        yield 'external host' => ['https://evil.com/boosters'];
        yield 'look-alike host' => ['https://ytcg.example.evil.com/'];
        yield 'protocol-relative' => ['//evil.com'];
        yield 'protocol-relative with path' => ['//evil.com/boosters'];
        yield 'backslash trick' => ['/\\evil.com'];
        yield 'backslash in an absolute URL' => ['https://ytcg.example\\@evil.com'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'javascript scheme, mixed case' => ['JavaScript:alert(document.cookie)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'userinfo' => ['https://evil.com@ytcg.example/'];
        yield 'ftp on the app host' => ['ftp://ytcg.example/file'];
        yield 'relative without slash' => ['boosters'];
        yield 'embedded newline' => ["/boosters\n//evil.com"];
        yield 'tab' => ["/\t/evil.com"];
        yield 'too long' => ['/' . str_repeat('a', 300)];
    }

    #[DataProvider('refusedLinks')]
    public function testEverythingElseIsRefused(string $link): void
    {
        $this->assertNull($this->policy()->toInternalPath($link));
    }

    public function testPathCheckUsedAtRenderTime(): void
    {
        $this->assertTrue(InternalLinkPolicy::isInternalPath('/boosters?code=ABCD'));
        $this->assertFalse(InternalLinkPolicy::isInternalPath('//evil.com'));
        $this->assertFalse(InternalLinkPolicy::isInternalPath('/\\evil.com'));
        $this->assertFalse(InternalLinkPolicy::isInternalPath('https://evil.com'));
        $this->assertFalse(InternalLinkPolicy::isInternalPath(''));
    }

    private function policy(): InternalLinkPolicy
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('https://ytcg.example/admin/announcements'));

        return new InternalLinkPolicy($requestStack, new RequestContext());
    }
}
