<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthenticateUser
{
    /**
     * Resolve the user for a login attempt, scoped to the current tenant.
     *
     * A user only authenticates if their tenant_id matches the tenant of the
     * domain being accessed. This is the cross-tenant isolation guarantee:
     * a credential valid for cabinet-a never logs in on cabinet-b's domain.
     */
    public function __invoke(Request $request): ?User
    {
        $tenant = tenant();

        $user = User::where('email', $request->email)
            ->where('tenant_id', $tenant?->id)
            ->first();

        if ($user && Hash::check($request->password, $user->password)) {
            return $user;
        }

        return null;
    }
}
