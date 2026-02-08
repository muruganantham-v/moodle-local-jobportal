# Installation and Setup Guide
## Moodle Job Portal Plugin

This guide will walk you through the complete installation and setup process for the Job Portal plugin.

## Prerequisites

Before you begin, ensure you have:
- Moodle 4.1 or higher installed and running
- Administrator access to your Moodle site
- SSH or FTP access to your Moodle server
- Basic knowledge of Moodle administration

## Step-by-Step Installation

### Step 1: Prepare the Plugin Files

1. Download or clone the plugin files to your local machine
2. Ensure the folder structure is correct:
   ```
   jobportal/
   ├── version.php
   ├── db/
   ├── lang/
   ├── index.php
   └── ... (other files)
   ```

### Step 2: Upload to Moodle

**Option A: Using SSH**
```bash
# Navigate to your Moodle local plugins directory
cd /path/to/your/moodle/local/

# Copy the plugin folder
cp -r /path/to/jobportal ./

# Set proper permissions
chown -R www-data:www-data jobportal
chmod -R 755 jobportal
```

**Option B: Using FTP**
1. Connect to your server via FTP
2. Navigate to `/moodle/local/`
3. Upload the `jobportal` folder
4. Ensure permissions are set correctly (755 for directories, 644 for files)

**Option C: Using Moodle's Plugin Installer**
1. Zip the plugin folder
2. Log in to Moodle as administrator
3. Go to: Site administration → Plugins → Install plugins
4. Upload the ZIP file
5. Follow the on-screen instructions

### Step 3: Install the Plugin

1. **Trigger Installation**
   - After uploading the files, Moodle will detect the new plugin
   - Navigate to: Site administration → Notifications
   - You should see a notification about the new plugin

2. **Review Plugin Information**
   - Plugin name: Job Portal
   - Plugin type: Local plugin
   - Version: 1.0
   - Check that all information is correct

3. **Upgrade Database**
   - Click "Upgrade Moodle database now"
   - Moodle will execute the database installation scripts
   - Three new tables will be created:
     - `mdl_local_jobportal_jobs`
     - `mdl_local_jobportal_applications`
     - `mdl_local_jobportal_profiles`

4. **Confirm Installation**
   - Wait for the installation to complete
   - You should see a success message
   - Click "Continue"

### Step 4: Verify Installation

1. **Check Plugin List**
   - Go to: Site administration → Plugins → Plugins overview
   - Search for "Job Portal"
   - Status should show as "Enabled"

2. **Check Database Tables**
   - If you have database access, verify the tables exist:
   ```sql
   SHOW TABLES LIKE '%jobportal%';
   ```

3. **Test Basic Access**
   - Navigate to: `https://yourmoodle.com/local/jobportal/`
   - You should see the job portal interface (even if empty)

### Step 5: Configure Permissions

1. **Review Default Permissions**
   - Go to: Site administration → Users → Permissions → Define roles
   - Check each role to see assigned capabilities:
     - Student: viewjobs, apply
     - Manager: all capabilities
     - Teacher: viewjobs (optional)

2. **Customize Permissions (Optional)**
   - Select a role to modify
   - Click "Edit"
   - Search for "jobportal"
   - Adjust capabilities as needed
   - Click "Save changes"

3. **Create a Custom Role (Optional)**
   If you want to create a "Recruiter" role:
   - Go to: Site administration → Users → Permissions → Define roles
   - Click "Add a new role"
   - Fill in role details:
     - Short name: recruiter
     - Custom full name: Job Recruiter
     - Description: Can post jobs and manage applications
   - Under capabilities, allow:
     - local/jobportal:postjobs
     - local/jobportal:managejobs
     - local/jobportal:viewapplications
   - Save the role

### Step 6: Add Navigation Link (Optional)

To make the Job Portal easily accessible:

**Option A: Add to Custom Menu**
1. Go to: Site administration → Appearance → Theme settings
2. Scroll to "Custom menu items"
3. Add the following line:
   ```
   Job Portal|/local/jobportal/
   ```
4. Click "Save changes"

**Option B: Add to Front Page**
1. Turn editing on from the front page
2. Add a "URL" resource or "Label"
3. Link to: `/local/jobportal/`

**Option C: Add to Dashboard**
1. Navigate to Dashboard
2. Turn editing on
3. Add a "HTML" block
4. Include a link to the job portal

### Step 7: Initial Setup and Testing

1. **Create a Test Job** (as Administrator)
   - Navigate to the Job Portal
   - Click "Post a Job"
   - Fill in job details
   - Submit the form
   - Verify the job appears in the listing

2. **Test Student Workflow**
   - Log in as a student user (or create one)
   - Navigate to Job Portal
   - View the job listing
   - Click on the job
   - Try applying
   - Upload a resume
   - Submit application

3. **Test Application Management**
   - Log back in as administrator
   - Go to the job details
   - Click "View Applications"
   - Verify you see the test application
   - Try changing the status

4. **Test Profile System**
   - As a student user
   - Click "My Profile"
   - Fill in profile details
   - Upload resume
   - Save changes

## Troubleshooting

### Plugin Not Appearing

**Problem**: Plugin doesn't show up after upload

**Solutions**:
1. Clear Moodle cache:
   - Site administration → Development → Purge all caches
2. Check file permissions:
   ```bash
   chmod -R 755 /path/to/moodle/local/jobportal
   ```
3. Verify correct directory structure
4. Check Moodle logs: Site administration → Reports → Logs

### Database Installation Fails

**Problem**: Error during database table creation

**Solutions**:
1. Check database user permissions
2. Review `db/install.xml` for syntax errors
3. Check Moodle database prefix in `config.php`
4. Try manual table creation using SQL export from `install.xml`

### Permission Errors

**Problem**: Users can't access certain features

**Solutions**:
1. Verify role capabilities:
   - Site administration → Users → Permissions → Check system permissions
   - Search for jobportal capabilities
2. Reset role to default:
   - Go to role definition
   - Click "Reset to default"
3. Clear cache after permission changes

### File Upload Issues

**Problem**: Resume upload fails

**Solutions**:
1. Check PHP upload limits:
   ```php
   upload_max_filesize = 10M
   post_max_size = 10M
   ```
2. Check Moodle file size limits:
   - Site administration → Security → Site policies
   - Check "Maximum uploaded file size"
3. Verify file permissions on moodledata directory

### Page Not Found Errors

**Problem**: 404 error when accessing job portal

**Solutions**:
1. Verify files are in correct location: `/moodle/local/jobportal/`
2. Check web server configuration
3. Clear browser cache
4. Check .htaccess file isn't blocking access

## Post-Installation Configuration

### Recommended Settings

1. **File Upload Limits**
   - Site administration → Security → Site policies
   - Set "Maximum uploaded file size" to at least 5MB

2. **User Profiles**
   - Site administration → Users → Accounts → User profile fields
   - Consider adding custom fields for job-related info

3. **Email Settings**
   - Ensure email is properly configured for notifications
   - Site administration → Server → Email

### Creating Sample Data

For testing or demonstration purposes:

```sql
-- Sample job posting
INSERT INTO mdl_local_jobportal_jobs 
(title, description, company, location, jobtype, salary, status, postedby, timecreated, timemodified)
VALUES 
('Software Developer', 'Looking for a talented developer...', 'Tech Company Inc.', 
'Chennai, India', 'fulltime', '₹500,000 - ₹800,000', 1, 2, UNIX_TIMESTAMP(), UNIX_TIMESTAMP());
```

## Next Steps

After successful installation:

1. **Train Users**
   - Create documentation for students on how to apply
   - Train administrators on job posting and application management

2. **Customize**
   - Adjust language strings if needed
   - Customize email templates
   - Add custom styling to match your site theme

3. **Monitor**
   - Check application submissions regularly
   - Monitor database table growth
   - Review and respond to student feedback

4. **Enhance**
   - Consider adding email notifications
   - Implement advanced search features
   - Add analytics for job postings

## Getting Help

If you encounter issues:
1. Check the README.md file for documentation
2. Review Moodle error logs
3. Verify all installation steps were completed
4. Test with Moodle debugging enabled:
   - Site administration → Development → Debugging
   - Set to "DEVELOPER" level temporarily

## Uninstallation

If you need to remove the plugin:

1. **Via Moodle Admin Interface**
   - Site administration → Plugins → Plugins overview
   - Find "Job Portal"
   - Click "Uninstall"
   - Confirm deletion
   - Database tables will be automatically removed

2. **Manual Removal**
   ```bash
   # Remove plugin directory
   rm -rf /path/to/moodle/local/jobportal
   
   # Manually drop tables (if needed)
   # DROP TABLE mdl_local_jobportal_jobs;
   # DROP TABLE mdl_local_jobportal_applications;
   # DROP TABLE mdl_local_jobportal_profiles;
   ```

## Backup Recommendations

Before installation and periodically after:
1. Backup Moodle database
2. Backup Moodle files directory
3. Keep a copy of original plugin files
4. Export job and application data regularly

---

**Installation Complete!** Your Moodle Job Portal is now ready to use.
