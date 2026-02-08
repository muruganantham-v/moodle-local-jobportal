# PERMISSION FIX - Moodle Job Portal

## Problem
Students are getting "Sorry, but you do not currently have permissions to do that (View job postings)" error.

## Root Cause
The plugin capabilities need to be manually assigned to roles after installation.

## Solution Options

### Option 1: Quick Manual Fix (Recommended for Already Installed Plugin)

1. **Log in as Administrator**

2. **Navigate to Role Permissions**
   - Go to: Site administration → Users → Permissions → Define roles

3. **Edit the Student Role**
   - Click on "Student" role
   - Click "Edit"
   - Scroll down to find "Filter" box
   - Type: `jobportal`

4. **Enable These Capabilities for Students**
   - ✅ `local/jobportal:viewjobs` → Set to **"Allow"**
   - ✅ `local/jobportal:apply` → Set to **"Allow"**

5. **Save Changes**
   - Click "Save changes" at the bottom
   - Students can now access the job portal!

6. **Repeat for Manager Role** (if needed)
   - Edit "Manager" role
   - Filter for `jobportal`
   - Enable ALL five capabilities:
     - ✅ `local/jobportal:viewjobs` → **"Allow"**
     - ✅ `local/jobportal:apply` → **"Allow"**
     - ✅ `local/jobportal:postjobs` → **"Allow"**
     - ✅ `local/jobportal:managejobs` → **"Allow"**
     - ✅ `local/jobportal:viewapplications` → **"Allow"**

7. **Clear Cache**
   - Go to: Site administration → Development → Purge all caches
   - Click "Purge all caches"

8. **Test**
   - Log in as a student
   - Navigate to the job portal
   - Should work now!

---

### Option 2: Reinstall with Auto-Permissions (New Installation)

If you're doing a fresh install or want automatic permission setup:

1. **Uninstall Current Plugin** (if installed)
   - Site administration → Plugins → Plugins overview
   - Search for "Job Portal"
   - Click "Uninstall"
   - Confirm (this will remove database tables)

2. **Delete Old Plugin Files**
   ```bash
   rm -rf /path/to/moodle/local/jobportal
   ```

3. **Install New Version**
   - Extract the new `moodle-job-portal-fixed.zip`
   - Copy to `/moodle/local/jobportal`
   - Go to: Site administration → Notifications
   - Click "Upgrade Moodle database now"
   - Permissions will be set automatically!

---

### Option 3: Using Moodle CLI (Advanced)

If you have SSH access, you can set permissions via command line:

```bash
cd /path/to/moodle

# Set student permissions
php admin/cli/cfg.php --component=local_jobportal --name=viewjobs \
  --set-role=student --set-capability=allow

# Or use this PHP script:
php admin/tool/task/cli/adhoc_task.php --execute=\\local_jobportal\\task\\setup_permissions
```

---

## Verification Steps

After applying any fix:

1. **Check Permissions Were Applied**
   - Site administration → Users → Permissions → Check system permissions
   - Select capability: `local/jobportal:viewjobs`
   - Check user: (select a student user)
   - Context: System
   - Click "Check"
   - Should show "Yes" for students

2. **Test as Student**
   - Log in as a student user
   - Go to: `/local/jobportal/`
   - Should see job portal without errors

3. **Test as Manager**
   - Log in as manager/admin
   - Should see "Post a Job" button
   - Should be able to post jobs

---

## Why This Happens

The original plugin defined capabilities in `db/access.php` with archetypes (suggested defaults), but Moodle doesn't automatically apply these to existing roles. They need to be either:

1. Manually assigned by administrators, OR
2. Set programmatically during installation via `db/install.php`

The fixed version includes `db/install.php` which automatically assigns permissions during installation.

---

## Prevention for Future

When creating Moodle plugins that add new capabilities:

1. Always include a `db/install.php` file
2. Use `assign_capability()` function during installation
3. Document permission requirements clearly
4. Test with different user roles

---

## Still Having Issues?

### Error: "Role not found"
- Make sure the role exists (Student, Manager, etc.)
- Check role shortnames match exactly: 'student', 'manager', 'editingteacher'

### Error: "Context not found"
- Ensure you're using system context: `context_system::instance()`

### Permissions not taking effect
- Always clear cache after permission changes
- Log out and log back in
- Check browser cache (try incognito mode)

### Need to reset everything?
```sql
-- Remove all jobportal capabilities (run as database admin)
DELETE FROM mdl_role_capabilities 
WHERE capability LIKE 'local/jobportal:%';
```
Then reapply permissions using Option 1 above.

---

## Quick Reference: Permission Matrix

| Role              | View Jobs | Apply | Post Jobs | Manage Jobs | View Applications |
|-------------------|-----------|-------|-----------|-------------|-------------------|
| Student           | ✅ Allow  | ✅ Allow | ❌ Prohibit | ❌ Prohibit | ❌ Prohibit      |
| Teacher           | ✅ Allow  | ❌ Prohibit | ❌ Prohibit | ❌ Prohibit | ❌ Prohibit      |
| Editing Teacher   | ✅ Allow  | ❌ Prohibit | ❌ Prohibit | ❌ Prohibit | ❌ Prohibit      |
| Manager           | ✅ Allow  | ✅ Allow | ✅ Allow    | ✅ Allow    | ✅ Allow          |
| Admin             | ✅ Allow  | ✅ Allow | ✅ Allow    | ✅ Allow    | ✅ Allow          |

---

**The manual fix (Option 1) is the quickest solution if you've already installed the plugin!**
