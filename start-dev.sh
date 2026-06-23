#!/bin/bash
# MLA CRM - Start Everything
# Run in XAMPP Control Panel first to start Apache + MySQL

echo "Starting MLA CRM Development Environment..."

# Start frontend (port 5173)
echo "Starting frontend..."
cd frontend && npm run dev &

# Start backend (port 8000)
echo "Starting backend..."
cd ../backend && php artisan serve &

echo ""
echo "MLA CRM is running at:"
echo "  Frontend:  http://localhost:5173"
echo "  Backend:   http://localhost:8000"
echo "  API Docs:  http://localhost:8000/api/auth/login (POST)"
echo ""
echo "Demo credentials:"
echo "  Admin:  admin@mlacrm.com  / Admin@12345"
echo "  Vendor: vendor@mlacrm.com / Vendor@12345"
echo "  TPV:    tpv@mlacrm.com    / TPV@12345"
echo "  Client: client@mlacrm.com / Client@12345"
