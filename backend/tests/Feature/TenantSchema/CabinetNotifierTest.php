<?php

use App\Mail\AppointmentCancelledMail;
use App\Models\Tenant;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Practitioner;
use App\Models\Tenant\Service;
use App\Models\User;
use App\Support\CabinetNotifier;
use Illuminate\Support\Facades\Mail;

it('returns the tenant_owner emails of the current tenant', function () {
    $owner = User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    // A user of another role must be excluded.
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'staff', 'email' => 'staff@kidsclub.de',
    ]);

    expect(CabinetNotifier::recipients())->toBe(['praxis@kidsclub.de']);
});

it('queues a cancellation mail to every cabinet recipient', function () {
    Mail::fake();
    User::factory()->create([
        'tenant_id' => $this->tenant->id, 'role' => 'tenant_owner', 'email' => 'praxis@kidsclub.de',
    ]);
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertQueued(AppointmentCancelledMail::class, fn ($m) => $m->hasTo('praxis@kidsclub.de'));
});

it('queues nothing when the cabinet has no tenant_owner', function () {
    Mail::fake();
    $p = Practitioner::factory()->create();
    $s = Service::factory()->create();
    $a = Appointment::factory()->create(['practitioner_id' => $p->id, 'service_id' => $s->id]);

    CabinetNotifier::notifyCancelled($a);

    Mail::assertNothingQueued();
});

it('never returns the tenant_owner of a different tenant', function () {
    tenancy()->end(); // leave the default test tenant

    $tenantA = Tenant::factory()->create(['id' => 'cabinet-a']);
    $tenantA->domains()->create(['domain' => 'cabinet-a.masinga-booking.test', 'is_primary' => true]);
    $tenantB = Tenant::factory()->create(['id' => 'cabinet-b']);
    $tenantB->domains()->create(['domain' => 'cabinet-b.masinga-booking.test', 'is_primary' => true]);

    User::factory()->create(['tenant_id' => 'cabinet-a', 'role' => 'tenant_owner', 'email' => 'a@kidsclub.de']);
    User::factory()->create(['tenant_id' => 'cabinet-b', 'role' => 'tenant_owner', 'email' => 'b@kidsclub.de']);

    tenancy()->initialize($tenantA);
    expect(CabinetNotifier::recipients())->toBe(['a@kidsclub.de']);
    tenancy()->end();
});
