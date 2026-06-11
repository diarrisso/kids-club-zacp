<?php

use App\Providers\AppServiceProvider;

it('defaults the session cookie to secure outside local and testing', function () {
    // The config default is computed from APP_ENV at config load; in the test
    // env it must be false (http test client), and the default expression must
    // yield true for production. We assert the production branch directly:
    $default = (require base_path('config/session.php'))['secure'];

    expect($default)->toBeFalse(); // APP_ENV=testing here

    // Simulate the production read of the same expression:
    putenv('APP_ENV=production');
    $_ENV['APP_ENV'] = 'production';
    $prod = (require base_path('config/session.php'))['secure'];
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';

    expect($prod)->toBeTrue();
});

it('forces https urls in production', function () {
    app()->detectEnvironment(fn () => 'production');
    (new AppServiceProvider(app()))->boot();

    expect(url('/storno/abc'))->toStartWith('https://');
});
