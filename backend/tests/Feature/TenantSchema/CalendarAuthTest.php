<?php

it('redirects guests away from the calendar', function () {
    $this->get('/termine')->assertRedirect();
});
