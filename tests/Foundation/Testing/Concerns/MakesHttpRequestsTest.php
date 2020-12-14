<?php

namespace Illuminate\Tests\Foundation\Testing\Concerns;

use Closure;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\RedirectResponse;
use Orchestra\Testbench\TestCase;

class MakesHttpRequestsTest extends TestCase
{
    public function testFromSetsHeaderAndSession()
    {
        $this->from('previous/url');

        $this->assertSame('previous/url', $this->defaultHeaders['referer']);
        $this->assertSame('previous/url', $this->app['session']->previousUrl());
    }

    public function testWithTokenSetsAuthorizationHeader()
    {
        $this->withToken('foobar');
        $this->assertSame('Bearer foobar', $this->defaultHeaders['Authorization']);

        $this->withToken('foobar', 'Basic');
        $this->assertSame('Basic foobar', $this->defaultHeaders['Authorization']);
    }

    public function testWithoutAndWithMiddleware()
    {
        $this->assertFalse($this->app->has('middleware.disable'));

        $this->withoutMiddleware();
        $this->assertTrue($this->app->has('middleware.disable'));
        $this->assertTrue($this->app->make('middleware.disable'));

        $this->withMiddleware();
        $this->assertFalse($this->app->has('middleware.disable'));
    }

    public function testWithoutAndWithMiddlewareWithParameter()
    {
        $next = function ($request) {
            return $request;
        };

        $this->assertFalse($this->app->has(MyMiddleware::class));
        $this->assertSame(
            'fooWithMiddleware',
            $this->app->make(MyMiddleware::class)->handle('foo', $next)
        );

        $this->withoutMiddleware(MyMiddleware::class);
        $this->assertTrue($this->app->has(MyMiddleware::class));
        $this->assertSame(
            'foo',
            $this->app->make(MyMiddleware::class)->handle('foo', $next)
        );

        $this->withMiddleware(MyMiddleware::class);
        $this->assertFalse($this->app->has(MyMiddleware::class));
        $this->assertSame(
            'fooWithMiddleware',
            $this->app->make(MyMiddleware::class)->handle('foo', $next)
        );
    }

    public function testWithCookieSetCookie()
    {
        $this->withCookie('foo', 'bar');

        $this->assertCount(1, $this->defaultCookies);
        $this->assertSame('bar', $this->defaultCookies['foo']);
    }

    public function testWithCookiesSetsCookiesAndOverwritesPreviousValues()
    {
        $this->withCookie('foo', 'bar');
        $this->withCookies([
            'foo' => 'baz',
            'new-cookie' => 'new-value',
        ]);

        $this->assertCount(2, $this->defaultCookies);
        $this->assertSame('baz', $this->defaultCookies['foo']);
        $this->assertSame('new-value', $this->defaultCookies['new-cookie']);
    }

    public function testWithUnencryptedCookieSetCookie()
    {
        $this->withUnencryptedCookie('foo', 'bar');

        $this->assertCount(1, $this->unencryptedCookies);
        $this->assertSame('bar', $this->unencryptedCookies['foo']);
    }

    public function testWithUnencryptedCookiesSetsCookiesAndOverwritesPreviousValues()
    {
        $this->withUnencryptedCookie('foo', 'bar');
        $this->withUnencryptedCookies([
            'foo' => 'baz',
            'new-cookie' => 'new-value',
        ]);

        $this->assertCount(2, $this->unencryptedCookies);
        $this->assertSame('baz', $this->unencryptedCookies['foo']);
        $this->assertSame('new-value', $this->unencryptedCookies['new-cookie']);
    }

    public function testWithoutAndWithCredentials()
    {
        $this->encryptCookies = false;

        $this->assertSame([], $this->prepareCookiesForJsonRequest());

        $this->withCredentials();
        $this->defaultCookies = ['foo' => 'bar'];
        $this->assertSame(['foo' => 'bar'], $this->prepareCookiesForJsonRequest());
    }

    public function testFollowingRedirects()
    {
        $this->withoutExceptionHandling();
        $router = $this->app->make(Registrar::class);
        $url = $this->app->make(UrlGenerator::class);

        $last_request = null;
        $middleware_terminations = [];
        MyTerminatingMiddleware::onTerminate(function() use (&$middleware_terminations, &$last_request) {
            $middleware_terminations[] = $last_request;
        });

        $router->get('test-redirect-from', function() use ($url, &$last_request) {
            $last_request = 'test-redirect-from';
            return new RedirectResponse($url->to('test-redirect-intermediate'));
        })->middleware(MyTerminatingMiddleware::class);

        $router->get('test-redirect-intermediate', function() use ($url, &$last_request) {
            $last_request = 'test-redirect-intermediate';
            return new RedirectResponse($url->to('test-redirect-to'));
        })->middleware(MyTerminatingMiddleware::class);

        $router->get('test-redirect-to', function() use (&$last_request) {
            $last_request = 'test-redirect-to';
            return 'OK';
        })->middleware(MyTerminatingMiddleware::class);

        $response = $this->followingRedirects()->get('test-redirect-from');

        dump($middleware_terminations);

        $response->assertOk();
        $response->assertSeeText('OK');
        $response->assertRedirectedTo('test-redirect-to');
        $response->assertRedirectedThrough('test-redirect-intermediate');
    }
}

class MyMiddleware
{
    public function handle($request, $next)
    {
        return $next($request.'WithMiddleware');
    }
}

class MyTerminatingMiddleware
{
    public static $onTerminate;

    public static function onTerminate($callback)
    {
        static::$onTerminate = $callback;
    }

    public function handle($request, $next)
    {
        return $next($request);
    }

    public function terminate()
    {
        if (static::$onTerminate instanceof Closure) {
            call_user_func(static::$onTerminate);
        }
    }
}
