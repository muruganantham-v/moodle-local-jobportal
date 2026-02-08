# Developer Guide - Moodle Job Portal Plugin

This guide is for developers who want to customize, extend, or understand the technical details of the Job Portal plugin.

## Architecture Overview

### Plugin Structure

The Job Portal follows Moodle's plugin architecture patterns:

```
local/jobportal/
├── Core Files
│   ├── version.php          # Plugin metadata and version
│   └── README.md            # User documentation
│
├── Database Layer
│   └── db/
│       ├── install.xml      # Database schema (XMLDB format)
│       ├── access.php       # Capability definitions
│       └── upgrade.php      # (Future) Database upgrade scripts
│
├── Presentation Layer
│   ├── index.php            # Job listings page
│   ├── view.php             # Job detail view
│   ├── apply.php            # Application submission
│   ├── post.php             # Job posting form
│   ├── myapplications.php   # Student applications view
│   ├── profile.php          # Student profile management
│   └── applications.php     # Admin application management
│
└── Localization
    └── lang/en/
        ├── local_jobportal.php      # Language strings
        └── local_jobportal_help.php # Help strings

```

### Data Model

#### Entity-Relationship Overview

```
┌─────────────────┐
│     User        │
│  (Moodle Core)  │
└────────┬────────┘
         │
         │ 1:N
         │
    ┌────┴─────────────────────────┐
    │                              │
┌───▼──────────────┐    ┌──────────▼────────┐
│  Jobs            │    │  Profiles         │
│  (Posted by)     │    │  (Student info)   │
└───┬──────────────┘    └───────────────────┘
    │
    │ 1:N
    │
┌───▼──────────────┐
│  Applications    │
│  (Student apps)  │
└──────────────────┘
```

#### Table: local_jobportal_jobs

Stores job postings with the following fields:

| Field        | Type          | Description                           |
|--------------|---------------|---------------------------------------|
| id           | INT(10)       | Primary key                           |
| title        | VARCHAR(255)  | Job title                             |
| description  | TEXT          | Full job description (HTML)           |
| company      | VARCHAR(255)  | Company name                          |
| location     | VARCHAR(255)  | Job location                          |
| jobtype      | VARCHAR(50)   | Type: fulltime/parttime/internship    |
| salary       | VARCHAR(100)  | Salary range                          |
| deadline     | INT(10)       | Application deadline (timestamp)      |
| requirements | TEXT          | Job requirements (HTML)               |
| status       | INT(2)        | Active (1) or Inactive (0)            |
| postedby     | INT(10)       | Foreign key to user table             |
| timecreated  | INT(10)       | Creation timestamp                    |
| timemodified | INT(10)       | Last modification timestamp           |

#### Table: local_jobportal_applications

| Field        | Type          | Description                           |
|--------------|---------------|---------------------------------------|
| id           | INT(10)       | Primary key                           |
| jobid        | INT(10)       | Foreign key to jobs table             |
| userid       | INT(10)       | Foreign key to user table             |
| coverletter  | TEXT          | Application cover letter              |
| resume       | VARCHAR(255)  | Resume file path                      |
| status       | VARCHAR(50)   | pending/reviewed/accepted/rejected    |
| timecreated  | INT(10)       | Application submission time           |
| timemodified | INT(10)       | Last status update time               |

**Unique Constraint**: (jobid, userid) - prevents duplicate applications

#### Table: local_jobportal_profiles

| Field        | Type          | Description                           |
|--------------|---------------|---------------------------------------|
| id           | INT(10)       | Primary key                           |
| userid       | INT(10)       | Foreign key to user table (unique)    |
| skills       | TEXT          | Student skills                        |
| experience   | TEXT          | Work experience                       |
| education    | TEXT          | Educational background                |
| resume       | VARCHAR(255)  | Default resume file path              |
| portfolio    | VARCHAR(255)  | Portfolio URL                         |
| timecreated  | INT(10)       | Profile creation time                 |
| timemodified | INT(10)       | Last profile update                   |

## Key Concepts

### 1. Moodle Capabilities

The plugin uses Moodle's capability system for access control:

```php
// Checking if user can view jobs
require_capability('local/jobportal:viewjobs', $context);

// Checking if user can apply
if (has_capability('local/jobportal:apply', $context)) {
    // Show apply button
}
```

**Defined Capabilities:**
- `viewjobs` - View job listings
- `apply` - Submit job applications
- `postjobs` - Create new job postings
- `managejobs` - Edit/delete job postings
- `viewapplications` - Access application data

### 2. Moodle Forms API

The plugin uses `moodleform` class for forms:

```php
class job_post_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        
        // Add form elements
        $mform->addElement('text', 'title', 'Job Title');
        $mform->setType('title', PARAM_TEXT);
        $mform->addRule('title', null, 'required');
        
        // Add buttons
        $this->add_action_buttons();
    }
    
    public function validation($data, $files) {
        // Custom validation
        $errors = parent::validation($data, $files);
        return $errors;
    }
}
```

### 3. File Management

Moodle's file API is used for resume uploads:

```php
// Prepare draft area for file upload
$draftitemid = file_get_submitted_draft_itemid('resume');
file_prepare_draft_area($draftitemid, $context->id, 
    'local_jobportal', 'resume', $itemid);

// Save files from draft area
file_save_draft_area_files($draftitemid, $context->id, 
    'local_jobportal', 'resume', $itemid, 
    array('subdirs' => 0, 'maxfiles' => 1));
```

### 4. Database Access

Using Moodle's database abstraction layer:

```php
global $DB;

// Get single record
$job = $DB->get_record('local_jobportal_jobs', 
    array('id' => $id), '*', MUST_EXIST);

// Get multiple records
$jobs = $DB->get_records('local_jobportal_jobs', 
    array('status' => 1), 'timecreated DESC');

// Complex queries
$sql = "SELECT a.*, j.title 
        FROM {local_jobportal_applications} a
        JOIN {local_jobportal_jobs} j ON a.jobid = j.id
        WHERE a.userid = :userid";
$apps = $DB->get_records_sql($sql, array('userid' => $USER->id));

// Insert record
$id = $DB->insert_record('local_jobportal_jobs', $jobdata);

// Update record
$DB->update_record('local_jobportal_jobs', $jobdata);
```

## Customization Guide

### Adding New Job Fields

1. **Update Database Schema** (`db/install.xml`):
```xml
<FIELD NAME="remote_work" TYPE="int" LENGTH="2" 
       NOTNULL="true" DEFAULT="0" SEQUENCE="false"/>
```

2. **Add Language String** (`lang/en/local_jobportal.php`):
```php
$string['remotework'] = 'Remote Work Available';
```

3. **Update Form** (`post.php`):
```php
$mform->addElement('advcheckbox', 'remote_work', 
    get_string('remotework', 'local_jobportal'));
```

4. **Display in View** (`view.php` and `index.php`):
```php
if ($job->remote_work) {
    echo html_writer::tag('span', 'Remote Available', 
        array('class' => 'badge badge-success'));
}
```

5. **Create Upgrade Script** (`db/upgrade.php`):
```php
function xmldb_local_jobportal_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();
    
    if ($oldversion < 2026013101) {
        $table = new xmldb_table('local_jobportal_jobs');
        $field = new xmldb_field('remote_work', XMLDB_TYPE_INTEGER, 
            '2', null, XMLDB_NOTNULL, null, '0');
        
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        
        upgrade_plugin_savepoint(true, 2026013101, 'local', 'jobportal');
    }
    
    return true;
}
```

### Adding Email Notifications

Create a new file `classes/notification.php`:

```php
<?php
namespace local_jobportal;

class notification {
    
    public static function application_submitted($application, $job) {
        global $DB, $USER;
        
        $poster = $DB->get_record('user', array('id' => $job->postedby));
        
        $subject = 'New Application: ' . $job->title;
        $message = "A new application has been submitted.\n\n";
        $message .= "Job: {$job->title}\n";
        $message .= "Applicant: " . fullname($USER) . "\n";
        $message .= "Email: {$USER->email}\n";
        
        email_to_user($poster, $USER, $subject, $message);
    }
    
    public static function application_status_changed($application, $newstatus) {
        global $DB;
        
        $applicant = $DB->get_record('user', 
            array('id' => $application->userid));
        $job = $DB->get_record('local_jobportal_jobs', 
            array('id' => $application->jobid));
        
        $subject = 'Application Status Update: ' . $job->title;
        $message = "Your application status has been updated.\n\n";
        $message .= "Job: {$job->title}\n";
        $message .= "New Status: {$newstatus}\n";
        
        email_to_user($applicant, 
            \core_user::get_noreply_user(), $subject, $message);
    }
}
```

Use in `apply.php`:
```php
require_once($CFG->dirroot . '/local/jobportal/classes/notification.php');

// After successful application
\local_jobportal\notification::application_submitted($application, $job);
```

### Adding Job Categories

1. **Create new table** in `db/install.xml`:
```xml
<TABLE NAME="local_jobportal_categories">
  <FIELDS>
    <FIELD NAME="id" TYPE="int" LENGTH="10" NOTNULL="true" SEQUENCE="true"/>
    <FIELD NAME="name" TYPE="char" LENGTH="100" NOTNULL="true"/>
    <FIELD NAME="description" TYPE="text" NOTNULL="false"/>
  </FIELDS>
  <KEYS>
    <KEY NAME="primary" TYPE="primary" FIELDS="id"/>
  </KEYS>
</TABLE>
```

2. **Add foreign key** to jobs table:
```xml
<FIELD NAME="categoryid" TYPE="int" LENGTH="10" NOTNULL="false"/>
<KEY NAME="categoryid" TYPE="foreign" FIELDS="categoryid" 
     REFTABLE="local_jobportal_categories" REFFIELDS="id"/>
```

3. **Create category management page** `categories.php`

4. **Update job form** to include category selector

### Adding Search Filters

Enhance `index.php` with advanced filtering:

```php
// Add filter form elements
$jobtype = optional_param('jobtype', '', PARAM_ALPHA);
$location = optional_param('location', '', PARAM_TEXT);

// Build dynamic SQL
$sql = "SELECT * FROM {local_jobportal_jobs} WHERE status = 1";
$params = array();

if (!empty($search)) {
    $sql .= " AND (title LIKE :search OR company LIKE :search2)";
    $params['search'] = "%$search%";
    $params['search2'] = "%$search%";
}

if (!empty($jobtype)) {
    $sql .= " AND jobtype = :jobtype";
    $params['jobtype'] = $jobtype;
}

if (!empty($location)) {
    $sql .= " AND location LIKE :location";
    $params['location'] = "%$location%";
}

$jobs = $DB->get_records_sql($sql, $params);
```

### Creating API Endpoints

For external integrations, create web services:

1. **Define functions** in `db/services.php`:
```php
$functions = array(
    'local_jobportal_get_jobs' => array(
        'classname'   => 'local_jobportal_external',
        'methodname'  => 'get_jobs',
        'description' => 'Get list of available jobs',
        'type'        => 'read',
        'capabilities'=> 'local/jobportal:viewjobs'
    )
);
```

2. **Implement in** `classes/external.php`:
```php
<?php
namespace local_jobportal;

use external_api;
use external_function_parameters;
use external_value;
use external_multiple_structure;

class external extends external_api {
    
    public static function get_jobs_parameters() {
        return new external_function_parameters(array());
    }
    
    public static function get_jobs() {
        global $DB;
        
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/jobportal:viewjobs', $context);
        
        $jobs = $DB->get_records('local_jobportal_jobs', 
            array('status' => 1));
        
        return array_values($jobs);
    }
    
    public static function get_jobs_returns() {
        return new external_multiple_structure(
            new external_single_structure(array(
                'id' => new external_value(CORE_TEXT, 'Job ID'),
                'title' => new external_value(CORE_TEXT, 'Job title'),
                // ... more fields
            ))
        );
    }
}
```

## Testing

### Unit Testing

Create `tests/jobportal_test.php`:

```php
<?php
namespace local_jobportal;

class jobportal_test extends \advanced_testcase {
    
    public function test_create_job() {
        global $DB;
        
        $this->resetAfterTest(true);
        
        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        
        $job = new \stdClass();
        $job->title = 'Test Job';
        $job->company = 'Test Company';
        $job->description = 'Test description';
        $job->jobtype = 'fulltime';
        $job->status = 1;
        $job->postedby = $user->id;
        $job->timecreated = time();
        $job->timemodified = time();
        
        $id = $DB->insert_record('local_jobportal_jobs', $job);
        
        $this->assertNotEmpty($id);
        
        $retrieved = $DB->get_record('local_jobportal_jobs', 
            array('id' => $id));
        $this->assertEquals('Test Job', $retrieved->title);
    }
    
    public function test_apply_for_job() {
        global $DB;
        
        $this->resetAfterTest(true);
        
        // Create job
        $poster = $this->getDataGenerator()->create_user();
        $student = $this->getDataGenerator()->create_user();
        
        $job = new \stdClass();
        $job->title = 'Developer';
        $job->company = 'Tech Co';
        $job->description = 'Great job';
        $job->jobtype = 'fulltime';
        $job->status = 1;
        $job->postedby = $poster->id;
        $job->timecreated = time();
        $job->timemodified = time();
        $jobid = $DB->insert_record('local_jobportal_jobs', $job);
        
        // Apply
        $this->setUser($student);
        
        $application = new \stdClass();
        $application->jobid = $jobid;
        $application->userid = $student->id;
        $application->coverletter = 'Hire me!';
        $application->status = 'pending';
        $application->timecreated = time();
        $application->timemodified = time();
        
        $appid = $DB->insert_record('local_jobportal_applications', 
            $application);
        
        $this->assertNotEmpty($appid);
        
        // Test duplicate application prevention
        $duplicate = $DB->record_exists('local_jobportal_applications',
            array('jobid' => $jobid, 'userid' => $student->id));
        
        $this->assertTrue($duplicate);
    }
}
```

Run tests:
```bash
php admin/tool/phpunit/cli/util.php --install
php admin/tool/phpunit/cli/util.php --buildcomponentconfigs
vendor/bin/phpunit local/jobportal/tests/jobportal_test.php
```

## Performance Optimization

### Database Indexes

Add indexes for frequently queried fields:

```xml
<INDEXES>
  <INDEX NAME="status-timecreated" UNIQUE="false" 
         FIELDS="status, timecreated"/>
  <INDEX NAME="company" UNIQUE="false" FIELDS="company"/>
  <INDEX NAME="jobtype" UNIQUE="false" FIELDS="jobtype"/>
</INDEXES>
```

### Caching

Implement caching for job listings:

```php
// In index.php
$cache = \cache::make('local_jobportal', 'jobs');

$cachekey = 'alljobs_' . $search;
$jobs = $cache->get($cachekey);

if ($jobs === false) {
    $jobs = $DB->get_records_sql($sql, $params);
    $cache->set($cachekey, $jobs);
}
```

Define cache in `db/caches.php`:
```php
$definitions = array(
    'jobs' => array(
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false
    )
);
```

## Security Best Practices

1. **Always validate input**:
```php
$id = required_param('id', PARAM_INT);
$search = optional_param('search', '', PARAM_TEXT);
```

2. **Check capabilities**:
```php
require_capability('local/jobportal:postjobs', $context);
```

3. **Use sesskey for state-changing operations**:
```php
require_sesskey();
```

4. **Sanitize output**:
```php
echo format_string($job->title);  // For plain text
echo format_text($job->description, FORMAT_HTML);  // For HTML
```

5. **Prevent SQL injection** (use placeholders):
```php
$DB->get_records_sql($sql, array('id' => $id));  // Good
$DB->get_records_sql("SELECT * WHERE id = $id");  // Bad!
```

## Debugging

Enable debugging in Moodle:
- Site administration → Development → Debugging
- Set to "DEVELOPER: extra Moodle debug messages for developers"

Add debug statements:
```php
debugging('Job ID: ' . $job->id, DEBUG_DEVELOPER);
```

Use var_dump with output buffering:
```php
ob_start();
var_dump($data);
$output = ob_get_clean();
debugging($output, DEBUG_DEVELOPER);
```

## Code Style

Follow Moodle coding style:
- https://moodledev.io/general/development/policies/codingstyle

Run code checker:
```bash
php local/codechecker/phpcs/bin/phpcs --standard=moodle local/jobportal/
```

## Contributing

When contributing:
1. Follow Moodle coding standards
2. Add appropriate comments
3. Include PHPDoc blocks
4. Write unit tests
5. Update documentation
6. Test on multiple Moodle versions

## Resources

- Moodle Developer Documentation: https://moodledev.io
- Moodle Plugin Directory: https://moodle.org/plugins
- Moodle Database API: https://docs.moodle.org/dev/Data_manipulation_API
- Moodle Forms API: https://docs.moodle.org/dev/Form_API

---

Happy coding! 🚀
