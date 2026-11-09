# Timetable Management System

Starter PHP/MySQL application for Government Polytechnic College, Kottayam. It includes a role based sign in, a one-time administrator setup, and a simple college details manager. Coordinator and staff sign-in are wired to separate role dashboards; their management features can be added next.

## Run with Docker

1. Copy `.env.example` to `.env`. For a local development environment, you can keep the sample passwords; choose strong passwords before exposing services beyond your machine.
2. From this directory, run `docker compose up -d --build`.
3. Open the app at [http://localhost:8090](http://localhost:8090). Create the administrator account on the first visit.
4. Open phpMyAdmin at [http://localhost:8091](http://localhost:8091). Sign in with the values from `.env`; the server/host is `mysql`.

MySQL initializes the schema from `database.sql` the first time its data volume is created. To stop the services, run `docker compose down`. Database contents persist in the `mysql_data` volume.

## Set up without Docker

1. Create a MySQL database (for example, `timetable_db`) in phpMyAdmin.
2. Import `database.sql` in phpMyAdmin.
3. Copy `config.example.php` to `config.php` and set the database host, database name, username, and password. With PHP and MySQL in Docker, use the MySQL service/container name as the host (not `localhost`, unless both run in the same container).
4. Put the `timetable-system` directory under the PHP web root and open it in a browser.
5. The first visit redirects to `setup.php`. Create the administrator username and password there. Setup disables itself after the first account is created.
6. Sign in as Administrator. Open **College details** to add a row such as `college_name` / `Government Polytechnic College, Kottayam`.

The login page shows the college name from the `details` table. Until a name is added, it uses the college's name shown in the supplied seal as a default. Replace that value through College details if needed.

## Tables

- `details`: unique setting key (`value_name`) and its string value (`value_value`).
- `users`: username, password hash, and role (`admin`, `coordinator`, or `staff`).

Do not upload `config.php` to a public repository. For InfinityFree, create the database and database user in its control panel, update `config.php`, and import the SQL using the hosting phpMyAdmin.


## Departments master data

Departments is a separate admin menu item alongside College Details. Admins can add, edit, list, and delete departments with a unique Department Code. For an existing database, import migrations/001_create_departments.sql in phpMyAdmin before using the page. Fresh databases get the departments table from database.sql.

For an existing departments table, also run migrations/002_department_program_optional.sql in phpMyAdmin to make Department Program Name optional.

## Semesters

Semesters is a separate admin menu below Departments. Admins can add, edit, and delete semesters. Semester Code is unique. For an existing database, import migrations/003_create_semesters.sql in phpMyAdmin; fresh databases get the semesters table from database.sql.

## Revisions

Revisions is a separate admin menu below Semesters. Admins can add, edit, and delete curriculum or syllabus revision records. For an existing database, import migrations/004_create_revisions.sql in phpMyAdmin. Fresh databases get the revisions table from database.sql.

## Courses

Courses is an admin menu above Departments. Its page presents Add Course, Assign Course, and View Courses options. Course records and assignment fields need to be specified before those workflows can be connected to the database.

## Course categories and types

Course Category and Course Types are separate admin menus. Course Category stores a revision-specific category; Course Types stores a single Course Type value. Add Course selects both values separately, along with department, revision, semester, course code, course name, and hours per week. Assign Course remains a placeholder until its workflow is designed.

For an existing database, import migrations/005_create_course_types.sql, 006_create_courses.sql, and 007_course_category_and_type.sql in order if they have not already been applied, then import migrations/008_separate_course_types_and_categories.sql. Migration 008 moves existing category records into course_categories and creates a clean course_types catalog. Fresh databases receive the current schema from database.sql.

Select2 and jQuery are bundled locally under assets/vendor, so searchable dropdowns do not depend on a CDN at runtime.
Existing databases should import migrations/009_unique_department_course_code.sql to enforce unique Course Codes within each department. Resolve any existing duplicates before importing the migration.

## Course Short Names

Add Course now requires Course Short Name, and View Courses displays it. The Course Short Names admin page lists existing courses with missing short names so admins can fill them in individually. Existing databases should import migrations/010_course_short_name.sql in phpMyAdmin. Fresh databases include the field in database.sql.

## Hours and Days

Admins manage timetable hour slots from Hours and day labels from Days. Hour IDs and Day IDs are entered explicitly; hour time fields use 24-hour time inputs. Existing databases should import migrations/011_create_hours_and_days.sql in phpMyAdmin. Fresh databases include both tables in database.sql.

## Hours and Days

Hours and Days are separate admin menus above Departments. Hour ID and Day ID are manually entered primary identifiers. Hours includes two number labels, two name labels, and a start and end time; Days includes two day name labels. Existing databases should import migrations/011_create_hours_and_days.sql in phpMyAdmin. Fresh databases include both tables in database.sql.

## Academic Year

Admins can manage Academic Year records with AY Name 1, AY Name 2, and AY Name 3 from the menu below Semesters. Existing databases should import migrations/013_create_academic_years.sql in phpMyAdmin. Fresh databases include the table in database.sql.

Semesters also have a required Even/Odd Semester Type. Existing databases should import migrations/014_semester_type.sql; then edit existing semesters to assign their type.

## Timetable records

The Timetable menu below Dashboard creates a timetable record using an Academic Year and one or more Semesters. The list provides View details and Delete actions. Existing databases should import migrations/017_timetable_multiple_semesters.sql in phpMyAdmin after the timetable table exists; it preserves prior semester links. Fresh databases include both timetable tables in database.sql.

Existing databases that already ran migration 015 should import migrations/016_remove_timetable_effect_from.sql to remove the date column.

Migration 017 also removes the retired effect_from field if it remains.

## Timetable slot course assignment

When viewing a timetable, each day, department, semester, and hour cell opens a subject assignment modal. The subject dropdown is limited to courses belonging to that department and semester. Existing databases should import migrations/018_timetable_slot_courses.sql after the timetable, days, hours, department, semester, and courses tables are available. Fresh databases include the slot assignment table in database.sql.

## Users

The Users menu appears below Timetable. User ID is the sign-in ID, and User Name is the display name. Accounts can be assigned to a Department and given Admin or User access. Admins can reset passwords, deny or restore login, edit accounts, and delete accounts. Existing databases should import migrations/019_users_management.sql; existing coordinator/staff roles migrate to User. The current active administrator cannot disable or delete their own account, and the last active administrator is protected.

Timetable administrators can enable Allow Editing on an individual timetable. Users can then edit grid slots only in their assigned Department; while disabled, user accounts see the grid read-only. Existing databases should import migrations/020_timetable_edit_permissions.sql. Fresh databases include this setting in database.sql.

User accounts have Courses and Change Password links below Timetable. Courses lists only the signed-in User's Department. Change Password verifies the current password and requires a matching new password of at least eight characters.


### Timetable subject batches

The Assign Courses modal adds one course and an optional batch at a time. Existing databases should import migrations/021_timetable_slot_batches.sql after migration 018 and populate batches with batch_name1 and batch_name2. The migration preserves existing slot assignments. Fresh databases include the batches table and batch-aware assignment table in database.sql.


### Timetable assignment ownership

Import migrations/022_timetable_assignment_owners.sql after migration 021. New slot assignments record the user ID and name snapshot. Admins can remove any assignment; users can remove only the assignments they created. Existing assignments have no owner and remain removable by admins.
