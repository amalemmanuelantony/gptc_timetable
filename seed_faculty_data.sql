-- Faculty and designation data for Government Polytechnic College, Kottayam.
-- Import after migrations/024_faculty_designations.sql and after adding departments
-- with these codes: EE, CE, ME, EL, CP, PL, GS, WS.
-- Faculty short names use initials (with a small disambiguation for Arun S / Ajith S).
-- Safe to re-run: existing designation names and faculty name + department pairs are skipped.

START TRANSACTION;

INSERT INTO designations (designation_name, designation_short_name)
SELECT seed.designation_name, seed.designation_short_name
FROM (
    SELECT 'Lecturer' AS designation_name, 'LEC' AS designation_short_name UNION ALL
    SELECT 'Demonstrator', 'DEM' UNION ALL
    SELECT 'Tradesman', 'TRD' UNION ALL
    SELECT 'Head of Department', 'HOD' UNION ALL
    SELECT 'Trade Instructor', 'TI' UNION ALL
    SELECT 'Professor', 'PROF' UNION ALL
    SELECT 'Assistant Instructor', 'AI' UNION ALL
    SELECT 'Associate Professor', 'AP' UNION ALL
    SELECT 'Assistant Professor', 'ASP' UNION ALL
    SELECT 'Physical Education Instructor', 'PEI' UNION ALL
    SELECT 'Instructor', 'INS' UNION ALL
    SELECT 'Workshop Instructor', 'WI' UNION ALL
    SELECT 'Workshop Superintendent', 'WS'
) AS seed
WHERE NOT EXISTS (
    SELECT 1 FROM designations d WHERE d.designation_name = seed.designation_name
);

INSERT INTO faculty (faculty_name, faculty_short_name, department_id, designation_id)
SELECT seed.faculty_name, seed.faculty_short_name, d.id, g.id
FROM (
    SELECT 'Renjith V R' AS faculty_name, 'RVR' AS faculty_short_name, 'EE' AS department_code, 'Lecturer' AS designation_name UNION ALL
    SELECT 'Sreekumar T', 'ST', 'EE', 'Lecturer' UNION ALL
    SELECT 'Lakshmipriya Asokan', 'LA', 'EE', 'Demonstrator' UNION ALL
    SELECT 'Bijumon K V', 'BKV', 'EE', 'Demonstrator' UNION ALL
    SELECT 'Adithyan G', 'AG', 'EE', 'Tradesman' UNION ALL
    SELECT 'Dileep K C', 'DKC', 'EE', 'Tradesman' UNION ALL
    SELECT 'Shibu Ganesh', 'SG', 'EE', 'Head of Department' UNION ALL
    SELECT 'Arunima R T', 'ART', 'CE', 'Lecturer' UNION ALL
    SELECT 'Shajimon C C', 'SCC', 'CE', 'Demonstrator' UNION ALL
    SELECT 'Ajikumar V T', 'AVT', 'CE', 'Demonstrator' UNION ALL
    SELECT 'Sumesh S', 'SS', 'CE', 'Tradesman' UNION ALL
    SELECT 'Jomy Joseph', 'JJ', 'ME', 'Lecturer' UNION ALL
    SELECT 'Balu S', 'BS', 'ME', 'Lecturer' UNION ALL
    SELECT 'Sandeep R', 'SR', 'ME', 'Lecturer' UNION ALL
    SELECT 'Arun K Sukumaran', 'AKS', 'ME', 'Lecturer' UNION ALL
    SELECT 'Sunil Bose C', 'SBC', 'ME', 'Lecturer' UNION ALL
    SELECT 'Manoj K N', 'MKN', 'ME', 'Demonstrator' UNION ALL
    SELECT 'Satheesh Kumar N V', 'SKNV', 'ME', 'Demonstrator' UNION ALL
    SELECT 'Ajith Kumar B', 'AKB', 'ME', 'Trade Instructor' UNION ALL
    SELECT 'Thomas P', 'TP', 'ME', 'Trade Instructor' UNION ALL
    SELECT 'Anish K R', 'AKR', 'ME', 'Tradesman' UNION ALL
    SELECT 'Anit Abraham', 'AA', 'ME', 'Tradesman' UNION ALL
    SELECT 'Dr. Sethu Lakshmi P', 'SLP', 'EL', 'Lecturer' UNION ALL
    SELECT 'Dr. Jinu Sebastian', 'JS', 'EL', 'Lecturer' UNION ALL
    SELECT 'Anseena R', 'AR', 'EL', 'Lecturer' UNION ALL
    SELECT 'Sigimon P M', 'SPM', 'EL', 'Trade Instructor' UNION ALL
    SELECT 'Sreeju P S', 'SPS', 'EL', 'Tradesman' UNION ALL
    SELECT 'Dr. Remadevi V N', 'RVN', 'CP', 'Professor' UNION ALL
    SELECT 'Johns Shaji', 'JS', 'CP', 'Assistant Instructor' UNION ALL
    SELECT 'Anila V R', 'AVR', 'CP', 'Lecturer' UNION ALL
    SELECT 'Rincy Sebastian', 'RS', 'CP', 'Head of Department' UNION ALL
    SELECT 'Hafeez Muhammed P U', 'HMPU', 'PL', 'Lecturer' UNION ALL
    SELECT 'Vishnu Mohan', 'VM', 'PL', 'Lecturer' UNION ALL
    SELECT 'Seema T R', 'STR', 'PL', 'Lecturer' UNION ALL
    SELECT 'Ajith R Nair', 'ARN', 'PL', 'Lecturer' UNION ALL
    SELECT 'Neethu Narayanan', 'NN', 'PL', 'Lecturer' UNION ALL
    SELECT 'Ajeesh M S', 'AMS', 'PL', 'Demonstrator' UNION ALL
    SELECT 'Renjitha R', 'RR', 'PL', 'Demonstrator' UNION ALL
    SELECT 'Johny O', 'JO', 'PL', 'Trade Instructor' UNION ALL
    SELECT 'Santhosh Kumar M P', 'SKMP', 'PL', 'Trade Instructor' UNION ALL
    SELECT 'Priyanka C D', 'PCD', 'PL', 'Tradesman' UNION ALL
    SELECT 'Anandhu Santhosh', 'AS', 'PL', 'Tradesman' UNION ALL
    SELECT 'Smitha V K', 'SVK', 'GS', 'Associate Professor' UNION ALL
    SELECT 'Kavitha Sadan', 'KS', 'GS', 'Assistant Professor' UNION ALL
    SELECT 'Kavitha Mathew', 'KM', 'GS', 'Assistant Professor' UNION ALL
    SELECT 'Manu K P', 'MKP', 'GS', 'Assistant Professor' UNION ALL
    SELECT 'Amal Emmanuel Antony', 'AEA', 'GS', 'Assistant Professor' UNION ALL
    SELECT 'Muhammed Shiju A', 'MSA', 'GS', 'Physical Education Instructor' UNION ALL
    SELECT 'Sivakeerthy A', 'SA', 'CE', 'Lecturer' UNION ALL
    SELECT 'Tinu Thomas', 'TT', 'CE', 'Trade Instructor' UNION ALL
    SELECT 'Anupriya Lakshmanan', 'AL', 'CE', 'Trade Instructor' UNION ALL
    SELECT 'Anju P S', 'APS', 'CP', 'Instructor' UNION ALL
    SELECT 'Anjana P Thankappan', 'APT', 'CP', 'Lecturer' UNION ALL
    SELECT 'Sinimon T C', 'STC', 'WS', 'Workshop Instructor' UNION ALL
    SELECT 'Manoj M K', 'MMK', 'WS', 'Workshop Instructor' UNION ALL
    SELECT 'Syamkumar V', 'SV', 'WS', 'Workshop Instructor' UNION ALL
    SELECT 'Arun S', 'ArS', 'WS', 'Workshop Instructor' UNION ALL
    SELECT 'Prasad T O', 'PTO', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Ajith T R', 'ATR', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Sooraj G', 'SG', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Shopy A M', 'SAM', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Shaiju P S', 'SPS', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Girish Gopal', 'GG', 'WS', 'Trade Instructor' UNION ALL
    SELECT 'Sudheeshkumar P', 'SP', 'WS', 'Tradesman' UNION ALL
    SELECT 'Manu G', 'MG', 'WS', 'Tradesman' UNION ALL
    SELECT 'Ajith S', 'AjS', 'WS', 'Tradesman' UNION ALL
    SELECT 'Sreenivas P R', 'SPR', 'WS', 'Tradesman' UNION ALL
    SELECT 'Sibin S', 'SS', 'WS', 'Tradesman' UNION ALL
    SELECT 'Vishnu Subash', 'VS', 'WS', 'Tradesman' UNION ALL
    SELECT 'Anil V', 'AV', 'WS', 'Workshop Superintendent' UNION ALL
    SELECT 'Ratheesh Kumar R', 'RKR', 'EE', 'Workshop Instructor' UNION ALL
    SELECT 'Jyothis George', 'JG', 'CE', 'Lecturer' UNION ALL
    SELECT 'Meenu Mohan', 'MM', 'CE', 'Lecturer' UNION ALL
    SELECT 'Lettisha M', 'LM', 'CE', 'Lecturer' UNION ALL
    SELECT 'Anish Prakash', 'AP', 'CE', 'Lecturer' UNION ALL
    SELECT 'Athira Rajan K M', 'ARKM', 'CP', 'Lecturer' UNION ALL
    SELECT 'Krishna Satheesh', 'KS', 'CP', 'Lecturer' UNION ALL
    SELECT 'Arun S Kumar', 'ASK', 'EE', 'Lecturer' UNION ALL
    SELECT 'Prince A Basheer', 'PAB', 'EE', 'Lecturer' UNION ALL
    SELECT 'Aravind M S', 'AMS', 'EE', 'Lecturer' UNION ALL
    SELECT 'Mathew P C', 'MPC', 'EE', 'Trade Instructor' UNION ALL
    SELECT 'Roy Reghunath', 'RR', 'EE', 'Trade Instructor' UNION ALL
    SELECT 'Jithin J S', 'JJS', 'EL', 'Lecturer' UNION ALL
    SELECT 'Sumesh Joe Cherian', 'SJC', 'EL', 'Head of Department' UNION ALL
    SELECT 'Tom Thomas', 'TT', 'EL', 'Lecturer' UNION ALL
    SELECT 'Praseeth R', 'PR', 'EL', 'Demonstrator' UNION ALL
    SELECT 'Parthasaradhi A G', 'PAG', 'EL', 'Demonstrator' UNION ALL
    SELECT 'Prasanna S', 'PS', 'EL', 'Trade Instructor' UNION ALL
    SELECT 'Vishnu S', 'VS', 'EL', 'Tradesman' UNION ALL
    SELECT 'Renjith R', 'RR', 'ME', 'Head of Department' UNION ALL
    SELECT 'Rafeena Fathima Rasheed', 'RFR', 'PL', 'Lecturer' UNION ALL
    SELECT 'Arya Govindan', 'AG', 'EL', 'Lecturer' UNION ALL
    SELECT 'Anu G Nair', 'AGN', 'GS', 'Lecturer' UNION ALL
    SELECT 'Arya Suresh', 'AS', 'GS', 'Lecturer'
) AS seed
JOIN departments d ON d.department_code = seed.department_code
JOIN designations g ON g.designation_name = seed.designation_name
WHERE NOT EXISTS (
    SELECT 1 FROM faculty f
    WHERE f.faculty_name = seed.faculty_name AND f.department_id = d.id
);

COMMIT;

-- Review imported records and confirm all expected department codes exist.
SELECT d.designation_name, d.designation_short_name
FROM designations d
WHERE d.designation_name IN (
    'Lecturer', 'Demonstrator', 'Tradesman', 'Head of Department', 'Trade Instructor',
    'Professor', 'Assistant Instructor', 'Associate Professor', 'Assistant Professor',
    'Physical Education Instructor', 'Instructor', 'Workshop Instructor', 'Workshop Superintendent'
)
ORDER BY d.designation_name;

SELECT f.faculty_name, f.faculty_short_name, dep.department_code, des.designation_name
FROM faculty f
JOIN departments dep ON dep.id = f.department_id
JOIN designations des ON des.id = f.designation_id
WHERE dep.department_code IN ('EE', 'CE', 'ME', 'EL', 'CP', 'PL', 'GS', 'WS')
ORDER BY dep.department_code, f.faculty_name;

SELECT expected.department_code AS missing_department_code
FROM (
    SELECT 'EE' AS department_code UNION ALL SELECT 'CE' UNION ALL SELECT 'ME' UNION ALL
    SELECT 'EL' UNION ALL SELECT 'CP' UNION ALL SELECT 'PL' UNION ALL SELECT 'GS' UNION ALL SELECT 'WS'
) AS expected
LEFT JOIN departments d ON d.department_code = expected.department_code
WHERE d.id IS NULL;
