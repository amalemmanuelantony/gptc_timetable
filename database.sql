CREATE TABLE IF NOT EXISTS details (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    value_name VARCHAR(100) NOT NULL UNIQUE,
    value_value TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hours (
    hour_id INT UNSIGNED NOT NULL PRIMARY KEY,
    hour_number_1 VARCHAR(20) NOT NULL,
    hour_number_2 VARCHAR(20) NOT NULL,
    hour_name_1 VARCHAR(80) NOT NULL,
    hour_name_2 VARCHAR(80) NOT NULL,
    hour_time_from TIME NOT NULL,
    hour_time_to TIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS days (
    day_id INT UNSIGNED NOT NULL PRIMARY KEY,
    day_name_1 VARCHAR(80) NOT NULL,
    day_name_2 VARCHAR(80) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(150) NOT NULL,
    department_short_name VARCHAR(80) NOT NULL,
    department_code VARCHAR(30) NOT NULL UNIQUE,
    department_program_name VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_departments_name (department_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    display_name VARCHAR(150) NULL,
    department_id INT UNSIGNED NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_department (department_id),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_departments (
    user_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, department_id),
    INDEX idx_user_departments_department (department_id),
    CONSTRAINT fk_user_departments_user FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_departments_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS designations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    designation_name VARCHAR(120) NOT NULL,
    designation_short_name VARCHAR(40) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_designations_name (designation_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faculty (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    faculty_name VARCHAR(150) NOT NULL,
    faculty_short_name VARCHAR(80) NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    designation_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_faculty_department (department_id),
    INDEX idx_faculty_designation (designation_id),
    CONSTRAINT fk_faculty_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_faculty_designation FOREIGN KEY (designation_id) REFERENCES designations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS semesters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    semester_full_name VARCHAR(150) NOT NULL,
    semester_short_name VARCHAR(80) NOT NULL,
    semester_code VARCHAR(30) NOT NULL UNIQUE,
    semester_year VARCHAR(50) NOT NULL,
    semester_type ENUM('Even', 'Odd') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_semesters_name (semester_full_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_years (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ay_name_1 VARCHAR(100) NOT NULL,
    ay_name_2 VARCHAR(100) NOT NULL,
    ay_name_3 VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS timetables (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT UNSIGNED NOT NULL,
    allow_editing TINYINT(1) NOT NULL DEFAULT 0,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_timetables_academic_year (academic_year_id),
    CONSTRAINT fk_timetables_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_semesters (
    timetable_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (timetable_id, semester_id),
    INDEX idx_timetable_semesters_semester (semester_id),
    CONSTRAINT fk_timetable_semesters_timetable FOREIGN KEY (timetable_id) REFERENCES timetables (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_timetable_semesters_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS revisions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    revision_name VARCHAR(150) NOT NULL,
    revision_short_name VARCHAR(80) NOT NULL,
    revision_year VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_revisions_name (revision_name),
    INDEX idx_revisions_year (revision_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



CREATE TABLE IF NOT EXISTS course_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    revision_id INT UNSIGNED NOT NULL,
    course_category VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_course_category_revision (revision_id, course_category),
    CONSTRAINT fk_course_categories_revision FOREIGN KEY (revision_id) REFERENCES revisions (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_types (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    course_type VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    revision_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    course_category_id INT UNSIGNED NOT NULL,
    course_type_id INT UNSIGNED NOT NULL,
    course_code VARCHAR(50) NOT NULL,
    course_name VARCHAR(180) NOT NULL,
    course_short_name VARCHAR(80) NULL,
    hours_per_week DECIMAL(5,2) UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_courses_department (department_id),
    UNIQUE KEY uq_courses_department_code (department_id, course_code),
    INDEX idx_courses_revision (revision_id),
    INDEX idx_courses_semester (semester_id),
    INDEX idx_courses_category (course_category_id),
    INDEX idx_courses_type (course_type_id),
    CONSTRAINT fk_courses_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_courses_revision FOREIGN KEY (revision_id) REFERENCES revisions (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_courses_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_courses_category FOREIGN KEY (course_category_id) REFERENCES course_categories (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_courses_type FOREIGN KEY (course_type_id) REFERENCES course_types (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batches (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    batch_name1 VARCHAR(40) NOT NULL,
    batch_name2 VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_batches_name1 (batch_name1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_slot_courses (
    assignment_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    timetable_id INT UNSIGNED NOT NULL,
    day_id INT UNSIGNED NOT NULL,
    hour_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    assigned_by_user_id INT UNSIGNED NULL,
    assigned_by_name VARCHAR(150) NULL,
    INDEX idx_timetable_slot_courses_day (day_id),
    INDEX idx_timetable_slot_courses_hour (hour_id),
    INDEX idx_timetable_slot_courses_department (department_id),
    INDEX idx_timetable_slot_courses_semester (semester_id),
    INDEX idx_timetable_slot_courses_course (course_id),
    INDEX idx_timetable_slot_courses_batch (batch_id),
    INDEX idx_timetable_slot_courses_assigned_by (assigned_by_user_id),
    CONSTRAINT fk_timetable_slot_courses_timetable FOREIGN KEY (timetable_id) REFERENCES timetables (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_timetable_slot_courses_day FOREIGN KEY (day_id) REFERENCES days (day_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_hour FOREIGN KEY (hour_id) REFERENCES hours (hour_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_department FOREIGN KEY (department_id) REFERENCES departments (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_semester FOREIGN KEY (semester_id) REFERENCES semesters (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_course FOREIGN KEY (course_id) REFERENCES courses (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_batch FOREIGN KEY (batch_id) REFERENCES batches (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_timetable_slot_courses_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timetable_slot_course_faculty (
    assignment_id INT UNSIGNED NOT NULL,
    faculty_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (assignment_id, faculty_id),
    INDEX idx_timetable_slot_course_faculty_faculty (faculty_id),
    CONSTRAINT fk_timetable_slot_course_faculty_assignment FOREIGN KEY (assignment_id)
        REFERENCES timetable_slot_courses (assignment_id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_timetable_slot_course_faculty_faculty FOREIGN KEY (faculty_id)
        REFERENCES faculty (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS generated_timetables (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    generation_id VARCHAR(80) NOT NULL,
    source_timetable_id INT UNSIGNED NOT NULL,
      output_type ENUM('master', 'department', 'individual', 'semester', 'department_semester') NOT NULL,
    is_public TINYINT(1) NOT NULL DEFAULT 0,
    target_department_id INT UNSIGNED NULL,
    target_faculty_id INT UNSIGNED NULL,
    access_department_id INT UNSIGNED NULL,
    snapshot_json LONGTEXT NOT NULL,
    generated_by_user_id INT UNSIGNED NULL,
    generated_by_name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_generated_timetables_generation_id (generation_id),
    INDEX idx_generated_timetables_source (source_timetable_id, created_at),
    INDEX idx_generated_timetables_access (access_department_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
