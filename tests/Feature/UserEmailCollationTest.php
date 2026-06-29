<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// AC3 — users.email uses a case-insensitive collation: Foo@x.com matches foo@x.com.
it('matches email case-insensitively at the database level', function () {
    $user = User::factory()->create(['email' => 'Foo@Example.com']);

    expect(User::where('email', 'foo@example.com')->exists())->toBeTrue()
        ->and(User::where('email', 'FOO@EXAMPLE.COM')->first()?->id)->toBe($user->id);
});

// AC3 — the unique index is enforced regardless of case.
it('rejects a duplicate email that differs only by case', function () {
    User::factory()->create(['email' => 'maya@example.com']);

    User::factory()->create(['email' => 'MAYA@example.com']);
})->throws(QueryException::class);

// AC3 — the migration's column defaults match the spec (insert email only).
it('defaults plan to free, timezone to Eastern, flags to false', function () {
    $id = DB::table('users')->insertGetId([
        'email' => 'defaults@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(User::find($id))
        ->plan->toBe('free')
        ->timezone->toBe('America/New_York')
        ->is_admin->toBeFalse()
        ->email_opted_out->toBeFalse();
});
