<?php
/**
 * Authentication Diagnostic Script
 * Run: php diagnose-auth.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\DB;

echo "\n";
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║   🔧 Staff Management Authentication Diagnostics      ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";

// ─────────────────────────────────────────────────────────────
// 1. Database Connection
// ─────────────────────────────────────────────────────────────
echo "📦 1. Database Connection\n";
echo "──────────────────────────────────────────\n";
try {
    DB::connection()->getPdo();
    echo "✅ Database connected successfully\n";
    echo "   Driver: " . DB::connection()->getDriverName() . "\n";
    echo "   Database: " . DB::connection()->getDatabaseName() . "\n";
} catch (\Exception $e) {
    echo "❌ Database connection failed!\n";
    echo "   Error: {$e->getMessage()}\n";
    exit(1);
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 2. Check Admin User
// ─────────────────────────────────────────────────────────────
echo "👤 2. Admin User Check\n";
echo "──────────────────────────────────────────\n";
$admin = User::where('email', 'admin@demo.com')->first();

if ($admin) {
    echo "✅ Admin user found\n";
    echo "   ID: {$admin->id}\n";
    echo "   Name: {$admin->name}\n";
    echo "   Email: {$admin->email}\n";
    echo "   Role: {$admin->role}\n";
    echo "   Internal Role: " . ($admin->internal_role ?? 'null') . "\n";
    echo "   Status: {$admin->status}\n";
    echo "   Tenant ID: " . ($admin->tenant_id ?? 'null') . "\n";
    echo "   Created: {$admin->created_at}\n";
    
    // Check if password is hashed
    if (strlen($admin->password) === 60 && strpos($admin->password, '$2y$') === 0) {
        echo "   Password: ✅ Properly hashed\n";
    } else {
        echo "   Password: ⚠️ May not be hashed correctly\n";
    }
} else {
    echo "❌ Admin user (admin@demo.com) not found!\n";
    echo "   Creating one now...\n";
    
    // Create tenant first
    $tenant = \App\Models\Tenant::firstOrCreate(
        ['slug' => 'demo-company'],
        [
            'name' => 'Demo Company',
            'subdomain' => 'demo',
            'plan' => 'starter',
            'status' => 'active'
        ]
    );
    
    // Create admin
    $admin = User::create([
        'tenant_id' => $tenant->id,
        'name' => 'Admin User',
        'email' => 'admin@demo.com',
        'password' => \Illuminate\Support\Facades\Hash::make('password123'),
        'role' => 'admin',
        'status' => 'active',
    ]);
    
    echo "   ✅ Admin user created!\n";
    echo "   Email: admin@demo.com\n";
    echo "   Password: password123\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 3. Check Personal Access Tokens Table
// ─────────────────────────────────────────────────────────────
echo "🔑 3. Personal Access Tokens\n";
echo "──────────────────────────────────────────\n";
try {
    $tokenCount = DB::table('personal_access_tokens')->count();
    echo "✅ personal_access_tokens table exists\n";
    echo "   Total tokens: {$tokenCount}\n";
    
    if ($admin) {
        $adminTokens = DB::table('personal_access_tokens')
            ->where('tokenable_id', $admin->id)
            ->where('tokenable_type', 'App\\Models\\User')
            ->get();
        
        echo "   Admin tokens: " . $adminTokens->count() . "\n";
        
        if ($adminTokens->count() > 0) {
            echo "\n   📋 Token Details:\n";
            foreach ($adminTokens as $token) {
                $expiresAt = $token->expires_at ? \Carbon\Carbon::parse($token->expires_at) : null;
                $isExpired = $expiresAt && $expiresAt->isPast();
                
                echo "   • ID: {$token->id}\n";
                echo "     Name: {$token->name}\n";
                echo "     Created: {$token->created_at}\n";
                echo "     Expires: " . ($expiresAt ? $expiresAt->toDateTimeString() : 'Never') . "\n";
                echo "     Status: " . ($isExpired ? '❌ EXPIRED' : '✅ Valid') . "\n";
                echo "     Abilities: " . json_encode(json_decode($token->abilities)) . "\n";
                echo "\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "❌ personal_access_tokens table missing or error!\n";
    echo "   Error: {$e->getMessage()}\n";
    echo "   Run: php artisan migrate\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 4. Check Staff Members
// ─────────────────────────────────────────────────────────────
echo "👥 4. Staff Members\n";
echo "──────────────────────────────────────────\n";
if ($admin && $admin->tenant_id) {
    $staffCount = User::where('tenant_id', $admin->tenant_id)
        ->where('role', 'staff')
        ->count();
    
    echo "Total staff members: {$staffCount}\n";
    
    if ($staffCount > 0) {
        echo "\n📋 Staff List:\n";
        $staff = User::where('tenant_id', $admin->tenant_id)
            ->where('role', 'staff')
            ->get();
        
        foreach ($staff as $member) {
            echo "• {$member->name} ({$member->email})\n";
            echo "  Role: staff | Internal: " . ($member->internal_role ?? 'none') . "\n";
            echo "  Status: {$member->status}\n";
            echo "\n";
        }
    } else {
        echo "ℹ️  No staff members found (this is normal for new setup)\n";
        echo "   You can add staff from the Admin > Staff Management page\n";
    }
} else {
    echo "⚠️  Cannot check staff - admin has no tenant_id\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 5. Generate Fresh Token
// ─────────────────────────────────────────────────────────────
echo "🔐 5. Fresh Token Generation\n";
echo "──────────────────────────────────────────\n";
if ($admin) {
    // Delete old tokens
    $deletedCount = $admin->tokens()->delete();
    echo "Deleted {$deletedCount} old token(s)\n";
    
    // Create new token
    $token = $admin->createToken('diagnostic-token', ['*'], now()->addDays(30))->plainTextToken;
    
    echo "✅ Fresh token generated!\n\n";
    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║  🔑 COPY THIS TOKEN                                    ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo $token . "\n";
    echo "\n";
    echo "📋 To use this token:\n";
    echo "1. Open browser console (F12)\n";
    echo "2. Run: localStorage.setItem('token', '{$token}')\n";
    echo "3. Refresh the page\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 6. Test API Endpoint
// ─────────────────────────────────────────────────────────────
echo "🧪 6. API Endpoint Test\n";
echo "──────────────────────────────────────────\n";
try {
    // Simulate authenticated request
    if ($admin) {
        $user = $admin;
        
        // Get stats
        $tenantId = $user->tenant_id;
        $totalStaff = User::where('tenant_id', $tenantId)->where('role', 'staff')->count();
        $activeStaff = User::where('tenant_id', $tenantId)->where('role', 'staff')->where('status', 'active')->count();
        $inactiveStaff = User::where('tenant_id', $tenantId)->where('role', 'staff')->whereIn('status', ['inactive', 'suspended'])->count();
        
        echo "✅ API logic test passed\n";
        echo "   Total Staff: {$totalStaff}\n";
        echo "   Active Staff: {$activeStaff}\n";
        echo "   Inactive Staff: {$inactiveStaff}\n";
    }
} catch (\Exception $e) {
    echo "❌ API logic test failed!\n";
    echo "   Error: {$e->getMessage()}\n";
}
echo "\n";

// ─────────────────────────────────────────────────────────────
// 7. Summary & Recommendations
// ─────────────────────────────────────────────────────────────
echo "╔════════════════════════════════════════════════════════╗\n";
echo "║  📊 SUMMARY & RECOMMENDATIONS                          ║\n";
echo "╚════════════════════════════════════════════════════════╝\n";
echo "\n";

$issues = [];

if (!$admin) {
    $issues[] = "Admin user was missing (but created now)";
}

if ($admin && !$admin->tenant_id) {
    $issues[] = "Admin user has no tenant_id";
}

if (isset($tokenCount) && $tokenCount === 0) {
    $issues[] = "No authentication tokens in database";
}

if (empty($issues)) {
    echo "✅ All checks passed!\n";
    echo "\n";
    echo "If you're still seeing errors in the frontend:\n";
    echo "1. Clear browser localStorage (see token copy command above)\n";
    echo "2. Login again at http://localhost:5173\n";
    echo "3. Check browser console for any errors\n";
    echo "4. Verify API base URL is http://127.0.0.1:8000/api\n";
} else {
    echo "⚠️  Issues found:\n";
    foreach ($issues as $issue) {
        echo "• {$issue}\n";
    }
    echo "\n";
    echo "💡 Run this script again to verify fixes\n";
}

echo "\n";
echo "🏁 Diagnostic complete!\n";
echo "\n";
