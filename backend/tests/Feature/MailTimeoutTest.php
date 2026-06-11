<?php

it('bounds the smtp timeout so a dead MX fails fast', function () {
    expect(config('mail.mailers.smtp.timeout'))->toBe(5);
});
