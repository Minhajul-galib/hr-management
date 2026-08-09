# 🔧 Troubleshooting Guide

## Issues Fixed ✅

### 1. **Vue Prop Type Error**
**Problem**: `Invalid prop: type check failed for prop "icon". Expected Object, got Function`

**Solution**: Updated MetricCard and ChartCard components to accept both Object and Function types for icons.

```javascript
// Fixed in MetricCard.vue and ChartCard.vue
icon: [Object, Function], // Allow both Object and Function
```

### 2. **Vite Dependency Error**
**Problem**: `GET http://[::1]:5173/node_modules/.vite/deps/@fullcalendar_vue3.js net::ERR_ABORTED 504`

**Solution**: Added proper Vite optimization configuration for FullCalendar dependencies.

```javascript
// Added to vite.config.js
optimizeDeps: {
    include: [
        '@fullcalendar/vue3',
        '@fullcalendar/core',
        '@fullcalendar/daygrid',
        '@fullcalendar/timegrid',
        '@fullcalendar/interaction',
        'vuedraggable'
    ]
}
```

## Common Issues & Solutions

### 🚨 **Calendar Page Not Loading**

**Symptoms:**
- Blank calendar page
- Console errors about missing dependencies
- 504 errors for FullCalendar modules

**Solutions:**
1. **Clear Vite Cache:**
   ```bash
   Remove-Item -Recurse -Force node_modules/.vite
   npm run build
   ```

2. **Reinstall Dependencies:**
   ```bash
   npm install @fullcalendar/core @fullcalendar/vue3 @fullcalendar/daygrid @fullcalendar/timegrid @fullcalendar/interaction vuedraggable@next --legacy-peer-deps
   ```

3. **Clear Laravel Cache:**
   ```bash
   php artisan cache:clear
   php artisan config:clear
   php artisan view:clear
   ```

### 🚨 **Database Errors**

**Symptoms:**
- Migration errors
- "Table already exists" errors
- Foreign key constraint errors

**Solutions:**
1. **Reset Migrations:**
   ```bash
   php artisan migrate:fresh --seed
   ```

2. **Setup Calendar System:**
   ```bash
   php artisan calendar:setup
   ```

### 🚨 **Authentication Issues**

**Symptoms:**
- Redirected to login page
- "Unauthenticated" errors
- Cannot access calendar

**Solutions:**
1. **Create User Account:**
   ```bash
   php artisan tinker
   User::factory()->create(['email' => 'admin@example.com', 'password' => bcrypt('password')])
   ```

2. **Run Dashboard Setup:**
   ```bash
   php artisan dashboard:setup
   ```

### 🚨 **Permission Errors**

**Symptoms:**
- "403 Forbidden" errors
- Cannot create holidays
- Missing menu items

**Solutions:**
1. **Assign Roles:**
   ```bash
   php artisan tinker
   $user = User::first();
   $user->assignRole('Super System Admin');
   ```

2. **Check Role Permissions:**
   ```bash
   php artisan permission:show
   ```

### 🚨 **Asset Loading Issues**

**Symptoms:**
- CSS not loading
- JavaScript errors
- Icons not displaying

**Solutions:**
1. **Rebuild Assets:**
   ```bash
   npm run build
   ```

2. **Development Mode:**
   ```bash
   npm run dev
   ```

3. **Check Vite Config:**
   - Ensure all dependencies are in `optimizeDeps.include`
   - Clear browser cache (Ctrl+F5)

### 🚨 **Reminder System Not Working**

**Symptoms:**
- No reminder notifications
- Scheduler not running
- Missing reminder logs

**Solutions:**
1. **Start Scheduler:**
   ```bash
   php artisan schedule:work
   ```

2. **Test Reminders Manually:**
   ```bash
   php artisan reminders:send
   ```

3. **Check Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

## Development Tips

### **Hot Reload Issues**
If changes aren't reflecting:
```bash
# Stop dev server
# Clear caches
php artisan cache:clear
Remove-Item -Recurse -Force node_modules/.vite
# Restart dev server
npm run dev
```

### **Database Reset**
For a fresh start:
```bash
php artisan migrate:fresh
php artisan calendar:setup
```

### **Performance Issues**
If calendar is slow:
1. Check database indexes
2. Limit event date ranges
3. Use pagination for large datasets
4. Enable query caching

### **Browser Compatibility**
Ensure modern browser support:
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

## Quick Diagnostics

### **Check System Status**
```bash
# Check routes
php artisan route:list --name=calendar

# Check migrations
php artisan migrate:status

# Check dependencies
npm list @fullcalendar/vue3

# Test database connection
php artisan tinker
DB::connection()->getPdo()
```

### **Verify Setup**
1. ✅ Database tables exist: `calendar_events`, `todos`
2. ✅ Routes accessible: `/calendar`, `/dashboard`
3. ✅ Assets built: `public/build/` directory exists
4. ✅ Dependencies installed: `node_modules/@fullcalendar/`
5. ✅ User authenticated: Can access dashboard

## Getting Help

### **Log Files**
- Laravel: `storage/logs/laravel.log`
- Vite: Browser console (F12)
- Database: Check `.env` database settings

### **Debug Mode**
Enable debug mode in `.env`:
```
APP_DEBUG=true
LOG_LEVEL=debug
```

### **Common Commands**
```bash
# Complete reset and setup
php artisan migrate:fresh
php artisan calendar:setup
npm run build

# Development workflow
npm run dev
php artisan serve
php artisan schedule:work
```

---

## ✅ **System Status Check**

After following this guide, verify:

1. **Calendar loads**: http://127.0.0.1:8000/calendar
2. **Dashboard works**: http://127.0.0.1:8000/dashboard
3. **No console errors**: Check browser F12 console
4. **Database connected**: Can create events/todos
5. **Authentication works**: Can login/logout
6. **Reminders active**: Check logs for reminder activity

If all checks pass, your system is working correctly! 🎉