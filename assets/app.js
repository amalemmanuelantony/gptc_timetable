(() => {
  let activeSlotCell = null;
  const slotForm = () => document.querySelector('.timetable-slot-assignment-form');
  const slotJson = (selector) => { try { return JSON.parse(document.querySelector(selector)?.textContent || '[]'); } catch { return []; } };
  const ensureSlotInfoPanels = () => {
    const subject = document.querySelector('#slot-course-select');
    const faculty = document.querySelector('#slot-faculty-select');
    if (subject && !document.querySelector('#slot-course-hours-info')) {
      const panel = document.createElement('div'); panel.id = 'slot-course-hours-info'; panel.className = 'slot-course-hours-info'; panel.hidden = true; panel.setAttribute('aria-live', 'polite');
      const visibleControl = subject.nextElementSibling?.classList.contains('select2') ? subject.nextElementSibling : subject;
      visibleControl.insertAdjacentElement('afterend', panel);
    }
    const facultyLabel = faculty?.closest('label');
    if (facultyLabel && !document.querySelector('#slot-faculty-live-info')) {
      const panel = document.createElement('div'); panel.id = 'slot-faculty-live-info'; panel.className = 'slot-faculty-live-info'; panel.hidden = true; panel.setAttribute('aria-live', 'polite'); facultyLabel.append(panel);
    }
    [subject, faculty].filter(Boolean).forEach((select) => {
      if (select.dataset.slotInfoListener === 'true') return;
      if (window.jQuery) window.jQuery(select).on('change.slotSelectionInfo select2:select.slotSelectionInfo select2:unselect.slotSelectionInfo', refreshSlotSelectionInfo);
      else select.addEventListener('change', refreshSlotSelectionInfo);
      select.dataset.slotInfoListener = 'true';
    });
    if (faculty && window.jQuery && faculty.dataset.slotClashListener !== 'true') {
      window.jQuery(faculty).on('select2:select.slotClash', (event) => {
        const selectedId = String(event.params?.data?.id || '');
        const clashes = getFacultyClashes([selectedId]);
        if (!selectedId || !clashes.length) return;
        [...faculty.options].forEach((option) => { if (option.value === selectedId) option.selected = false; });
        const remainingIds = [...faculty.selectedOptions].map((option) => option.value);
        window.jQuery(faculty).val(remainingIds).trigger('change.select2');
        refreshSlotSelectionInfo();
        const facultyName = event.params?.data?.text || 'This faculty member';
        const details = clashes.map((item) => `Department: ${item.departmentName} (${item.departmentCode})\nSemester: ${item.semesterFullName} (${item.semesterCode})\nHour: ${item.hourName}\nCourse: ${item.courseCode} · ${item.courseName}`).join('\n\n');
        slotFeedback(`${facultyName} already has an assignment during this hour:\n\n${details}`, true);
      });
      faculty.dataset.slotClashListener = 'true';
    }
  };
  const makeInfoTable = (headers, rows, className = '') => {
    const table = document.createElement('table'); table.className = `slot-live-table ${className}`.trim();
    const head = document.createElement('thead'); const headerRow = document.createElement('tr');
    headers.forEach((value) => { const th = document.createElement('th'); th.textContent = value; headerRow.append(th); }); head.append(headerRow); table.append(head);
    const body = document.createElement('tbody');
    rows.forEach((values) => { const row = document.createElement('tr'); values.forEach((value) => {
      const cell = document.createElement('td');
      if (value && typeof value === 'object') {
        if (value.title) cell.title = value.title;
        if (value.facultyId) { const button = document.createElement('button'); button.type = 'button'; button.className = 'slot-faculty-timetable-open'; button.dataset.facultyId = String(value.facultyId); button.textContent = value.text || 'View'; cell.append(button); }
        else cell.textContent = value.text || '—';
      } else cell.textContent = value || '—';
      row.append(cell);
    }); body.append(row); });
    table.append(body); return table;
  };
  const getFacultyClashes = (facultyIds) => {
    const form = slotForm(); if (!form) return [];
    const dayId = String(form.querySelector('#slot-day-id')?.value || '');
    const hourId = String(form.querySelector('#slot-hour-id')?.value || '');
    const departmentId = String(form.querySelector('#slot-department-id')?.value || '');
    const semesterId = String(form.querySelector('#slot-semester-id')?.value || '');
    const availability = slotJson('#timetable-faculty-slot-availability');
    const facultyOptions = slotJson('#timetable-faculty-options');
    const clashes = []; const seen = new Set();
    facultyIds.forEach((id) => {
      const faculty = facultyOptions.find((item) => String(item.id) === String(id));
      availability.filter((item) => String(item.faculty_id) === String(id) && String(item.day_id) === dayId && String(item.hour_id) === hourId && !(String(item.department_id) === departmentId && String(item.semester_id) === semesterId)).forEach((item) => {
        const key = `${id}:${item.department_id}:${item.semester_id}:${item.day_id}:${item.hour_id}:${item.course_id}`;
        if (seen.has(key)) return;
        seen.add(key);
        clashes.push({facultyName: faculty?.faculty_name || item.faculty_name, departmentName: item.department_name, departmentCode: item.department_code, semesterFullName: item.semester_full_name, semesterCode: item.semester_code, hourName: item.hour_name_2 || item.hour_name_1, courseCode: item.course_code, courseName: item.course_name});
      });
    });
    return clashes;
  };
  const refreshSlotSelectionInfo = () => {
    const form = slotForm(); if (!form) return;
    const courseInfo = document.querySelector('#slot-course-hours-info');
    const courseId = form.querySelector('#slot-course-select')?.value || '';
    if (courseInfo) {
      courseInfo.replaceChildren(); courseInfo.hidden = !courseId;
      if (courseId) {
        const course = slotJson('#timetable-course-options').find((item) => String(item.id) === String(courseId));
        if (course) {
          const assigned = new Set(slotJson('#timetable-slot-course-hour-counts').filter((item) => String(item.course_id) === String(course.id)).map((item) => `${item.day_id}:${item.hour_id}`)).size;
          const strong = document.createElement('strong'); strong.textContent = `Total hours: ${course.hours_per_week ?? '—'} · Hours assigned: ${assigned}`; courseInfo.append(strong);
        }
      }
    }
    const facultyInfo = document.querySelector('#slot-faculty-live-info');
    const facultySelect = form.querySelector('#slot-faculty-select');
    const selectedIds = facultySelect ? [...facultySelect.selectedOptions].map((option) => String(option.value)) : [];
    if (!facultyInfo) return;
    facultyInfo.replaceChildren(); facultyInfo.hidden = selectedIds.length === 0;
    if (!selectedIds.length) return;
    const dayId = String(form.querySelector('#slot-day-id')?.value || '');
    const hourId = String(form.querySelector('#slot-hour-id')?.value || '');
    const departmentId = String(form.querySelector('#slot-department-id')?.value || '');
    const semesterId = String(form.querySelector('#slot-semester-id')?.value || '');
    const availability = slotJson('#timetable-faculty-slot-availability');
    const hours = slotJson('#timetable-hour-options');
    const hourIndex = hours.findIndex((item) => String(item.hour_id) === hourId);
    const adjacentId = (offset) => hourIndex >= 0 && hours[hourIndex + offset] ? String(hours[hourIndex + offset].hour_id) : '';
    const previousHourId = adjacentId(-1); const nextHourId = adjacentId(1);
    const facultyOptions = slotJson('#timetable-faculty-options');
    const clashes = getFacultyClashes(selectedIds); const details = [];
    selectedIds.forEach((id) => {
      const faculty = facultyOptions.find((item) => String(item.id) === id);
      const ownRows = availability.filter((item) => String(item.faculty_id) === id);
      const workload = new Set(ownRows.map((item) => `${item.day_id}:${item.hour_id}`)).size;
      const assignmentsAt = (targetHourId) => {
        const assignments = [...new Map(ownRows.filter((item) => String(item.day_id) === dayId && String(item.hour_id) === targetHourId).map((item) => [`${item.department_id}:${item.semester_id}:${item.course_id}`, item])).values()];
        if (!assignments.length) return 'Not Assigned';
        return {text: assignments.map((item) => `${item.department_code} · ${item.semester_code} · ${item.course_short_name || item.course_code}`).join('\n'), title: assignments.map((item) => item.course_name).join('\n')};
      };
      const viewTimetable = {text: 'View', facultyId: id};
      details.push([faculty?.faculty_name || 'Unknown faculty', `${workload} hour${workload === 1 ? '' : 's'}`, assignmentsAt(previousHourId), assignmentsAt(nextHourId), viewTimetable]);
    });
    const heading = document.createElement('strong'); heading.className = 'slot-live-section-title'; heading.textContent = 'Selected faculty details'; facultyInfo.append(heading);
    facultyInfo.append(makeInfoTable(['Faculty', 'Workload', 'Preceding slot', 'Next slot', 'Individual timetable'], details, 'slot-faculty-detail-table'));
    if (clashes.length) {
      const warning = document.createElement('div'); warning.className = 'slot-faculty-clashes';
      const warningTitle = document.createElement('strong'); warningTitle.textContent = 'Hour clash detected'; warning.append(warningTitle);
      warning.append(makeInfoTable(['Faculty', 'Department', 'Semester', 'Hour', 'Course'], clashes.map((item) => [item.facultyName, `${item.departmentName} (${item.departmentCode})`, `${item.semesterFullName} (${item.semesterCode})`, item.hourName, `${item.courseCode} · ${item.courseName}`]), 'slot-faculty-clash-table'));
      facultyInfo.append(warning);
    }
  };
  const buildFacultyIndividualTimetable = (facultyId) => {
    const availability = slotJson('#timetable-faculty-slot-availability').filter((item) => String(item.faculty_id) === String(facultyId));
    const days = slotJson('#timetable-day-options'); const hours = slotJson('#timetable-hour-options');
    const tableWrap = document.createElement('div'); tableWrap.className = 'table-wrap faculty-individual-table-wrap';
    const table = document.createElement('table'); table.className = 'faculty-individual-grid';
    const thead = document.createElement('thead'); const headRow = document.createElement('tr');
    const dayHead = document.createElement('th'); dayHead.textContent = 'Day'; headRow.append(dayHead);
    hours.forEach((hour) => { const th = document.createElement('th'); th.textContent = hour.hour_name_2 || hour.hour_name_1; th.title = `${hour.hour_name_1 || ''} · ${hour.hour_time_from || ''} – ${hour.hour_time_to || ''}`; headRow.append(th); });
    thead.append(headRow); table.append(thead);
    const tbody = document.createElement('tbody');
    days.forEach((day) => {
      const row = document.createElement('tr'); const dayCell = document.createElement('th'); dayCell.textContent = day.day_name_1; row.append(dayCell);
      hours.forEach((hour) => {
        const cell = document.createElement('td');
        const assignments = [...new Map(availability.filter((item) => String(item.day_id) === String(day.day_id) && String(item.hour_id) === String(hour.hour_id)).map((item) => [String(item.assignment_id), item])).values()];
        if (!assignments.length) cell.textContent = '—';
        assignments.forEach((item) => {
          const entry = document.createElement('div'); entry.className = 'faculty-individual-entry'; entry.title = item.course_name || '';
          const course = document.createElement('strong'); course.textContent = item.course_short_name || item.course_code; entry.append(course);
          const context = document.createElement('small'); context.textContent = `${item.department_code} · ${item.semester_code}${item.batch_name1 ? ` · ${item.batch_name1}` : ''}`; entry.append(context);
          cell.append(entry);
        });
        row.append(cell);
      });
      tbody.append(row);
    });
    table.append(tbody); tableWrap.append(table);
    const report = document.createElement('div'); report.className = 'faculty-individual-report'; report.append(tableWrap);
    const courseHours = new Map(); const totalSlots = new Set();
    availability.forEach((item) => {
      const key = `${item.department_id}:${item.semester_id}:${item.course_id}`;
      if (!courseHours.has(key)) courseHours.set(key, {...item, slots: new Set()});
      courseHours.get(key).slots.add(`${item.day_id}:${item.hour_id}`);
      totalSlots.add(`${item.day_id}:${item.hour_id}`);
    });
    const summary = document.createElement('section'); summary.className = 'faculty-individual-hours-summary';
    const summaryTitle = document.createElement('h3'); summaryTitle.textContent = 'Hours Summary'; summary.append(summaryTitle);
    const summaryTable = document.createElement('table'); summaryTable.className = 'faculty-individual-hours-table';
    const summaryHead = document.createElement('thead'); const summaryHeadRow = document.createElement('tr');
    ['Sl No.', 'Department', 'Semester', 'Course', 'Hours'].forEach((label) => { const th = document.createElement('th'); th.textContent = label; summaryHeadRow.append(th); }); summaryHead.append(summaryHeadRow); summaryTable.append(summaryHead);
    const summaryBody = document.createElement('tbody');
    [...courseHours.values()].sort((a, b) => `${a.department_code}${a.semester_code}${a.course_code}`.localeCompare(`${b.department_code}${b.semester_code}${b.course_code}`)).forEach((item, index) => {
      const row = document.createElement('tr');
      [String(index + 1), item.department_code, item.semester_code].forEach((value) => { const td = document.createElement('td'); td.textContent = value || ''; row.append(td); });
      const course = document.createElement('td'); course.textContent = item.course_short_name || item.course_code; course.title = item.course_name || ''; row.append(course);
      const assigned = document.createElement('td'); assigned.textContent = String(item.slots.size); row.append(assigned); summaryBody.append(row);
    });
    if (!courseHours.size) { const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 5; cell.textContent = 'No assignments for this faculty in the timetable.'; cell.className = 'muted'; row.append(cell); summaryBody.append(row); }
    summaryTable.append(summaryBody); const summaryWrap = document.createElement('div'); summaryWrap.className = 'table-wrap faculty-individual-hours-wrap'; summaryWrap.append(summaryTable); summary.append(summaryWrap);
    const total = document.createElement('p'); total.className = 'faculty-individual-total-workload'; total.textContent = `Total workload: ${totalSlots.size} hour${totalSlots.size === 1 ? '' : 's'}`; summary.append(total);
    report.append(summary); return report;
  };
  const ensureFacultyTimetableModal = (id, titleId) => {
    let modal = document.querySelector(`#${id}`);
    if (modal) return modal;
    modal = document.createElement('div'); modal.id = id; modal.className = 'timetable-modal faculty-individual-modal'; modal.hidden = true;
    const backdrop = document.createElement('div'); backdrop.className = 'timetable-modal-backdrop'; backdrop.dataset.modalClose = '';
    const card = document.createElement('section'); card.className = 'timetable-modal-card faculty-individual-card'; card.setAttribute('role', 'dialog'); card.setAttribute('aria-modal', 'true'); card.setAttribute('aria-labelledby', titleId);
    const close = document.createElement('button'); close.type = 'button'; close.className = 'timetable-modal-close'; close.dataset.modalClose = ''; close.setAttribute('aria-label', 'Close'); close.textContent = '×';
    const title = document.createElement('h2'); title.id = titleId;
    const wrap = document.createElement('div'); wrap.className = 'faculty-individual-content';
    card.append(close, title, wrap); modal.append(backdrop, card); document.body.append(modal); return modal;
  };
  const openFacultyIndividualTimetable = (facultyId) => {
    const faculty = slotJson('#timetable-faculty-options').find((item) => String(item.id) === String(facultyId));
    const modal = ensureFacultyTimetableModal('slot-faculty-timetable-modal', 'slot-faculty-timetable-title');
    modal.querySelector('#slot-faculty-timetable-title').textContent = `Individual Timetable · ${faculty?.faculty_name || 'Faculty'}`;
    const content = modal.querySelector('.faculty-individual-content'); content.replaceChildren(buildFacultyIndividualTimetable(facultyId));
    modal.hidden = false; modal.querySelector('.timetable-modal-close').focus();
  };
  const openFacultyTimetablePicker = () => {
    const modal = ensureFacultyTimetableModal('slot-faculty-timetable-picker-modal', 'slot-faculty-timetable-picker-title');
    const title = modal.querySelector('#slot-faculty-timetable-picker-title'); title.textContent = 'View Individual Timetable';
    let picker = modal.querySelector('#slot-faculty-timetable-picker');
    if (!picker) {
      const label = document.createElement('label'); label.className = 'faculty-timetable-picker-label'; label.textContent = 'Faculty';
      picker = document.createElement('select'); picker.id = 'slot-faculty-timetable-picker'; picker.className = 'js-select2'; picker.dataset.placeholder = 'Select a faculty';
      const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Select a faculty'; picker.append(placeholder);
      slotJson('#timetable-faculty-options').forEach((person) => { const option = document.createElement('option'); option.value = String(person.id); option.textContent = `${person.faculty_name}${person.faculty_short_name ? ` · ${person.faculty_short_name}` : ''}`; picker.append(option); });
      label.append(picker); const content = document.createElement('div'); content.className = 'faculty-individual-content';
      const card = modal.querySelector('.faculty-individual-card'); card.insertBefore(label, card.querySelector('.faculty-individual-content')); card.querySelector('.faculty-individual-content').remove(); card.append(content);
      const render = () => { content.replaceChildren(); if (picker.value) content.append(buildFacultyIndividualTimetable(picker.value)); };
      if (window.jQuery) window.jQuery(picker).on('change.facultyTimetablePicker', render); else picker.addEventListener('change', render);
    }
    modal.hidden = false; initializeWidgets(modal);
    const select = modal.querySelector('#slot-faculty-timetable-picker');
    if (window.jQuery && window.jQuery.fn.select2) window.jQuery(select).val('').trigger('change'); else { select.value = ''; select.dispatchEvent(new Event('change', {bubbles: true})); }
    modal.querySelector('.timetable-modal-close').focus();
  };
  const savedCourseFacultyCombinations = () => {
    try { return JSON.parse((document.querySelector('#timetable-course-faculty-combinations') || document.querySelector('#course-faculty-combo-data'))?.textContent || '[]'); }
    catch { return []; }
  };
  const reportViewSeen = {summary: new Set(), workload: new Set(), courseHours: new Set()};
  const logReportView = (action, targetId, extraId = '') => {
    const group = action === 'summary_view' ? reportViewSeen.summary : action === 'faculty_workload_view' ? reportViewSeen.workload : reportViewSeen.courseHours;
    const key = `${targetId}:${extraId}`;
    if (group.has(key)) return;
    group.add(key);
    const csrf = document.querySelector('.timetable-slot-assignment-form input[name="csrf"]')?.value || '';
    const timetableId = new URL(window.location.href).searchParams.get('view') || '';
    const data = new FormData(); data.set('csrf', csrf); data.set('action', action); data.set('target_id', targetId); data.set('extra_id', extraId); data.set('timetable_id', timetableId);
    fetch(new URL('log-user-activity.php', document.baseURI).href, {method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'fetch','Accept':'application/json'}}).catch(() => {});
  };
  const syncSavedCourseFacultyCombinations = (items) => {
    ['#timetable-course-faculty-combinations', '#course-faculty-combo-data'].forEach((selector) => {
      const element = document.querySelector(selector);
      if (element) element.textContent = JSON.stringify(items);
    });
  };
  const makeCombinationRow = (item) => {
    const row = document.createElement('tr'); row.dataset.combinationId = String(item.id);
    [item.department_label, item.semester_label, item.course_label].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value || ''; row.append(cell); });
    const codeCell = document.createElement('td'); const code = document.createElement('code'); code.textContent = item.combination_code; codeCell.append(code); row.append(codeCell);
    const facultyCell = document.createElement('td'); facultyCell.textContent = item.faculty_names || ''; row.append(facultyCell);
    const actions = document.createElement('td'); actions.className = 'row-actions';
    const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'combo-edit timetable-combo-edit'; edit.textContent = 'Edit'; edit.dataset.item = JSON.stringify(item);
    const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'combo-delete timetable-combo-delete'; remove.textContent = 'Delete'; remove.dataset.id = String(item.id);
    actions.append(edit, remove); row.append(actions); return row;
  };
  const renderTimetableCombinationList = (items) => {
    const list = document.querySelector('#timetable-combo-list');
    if (!list) return;
    list.replaceChildren();
    if (!items.length) { const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 6; cell.className = 'muted'; cell.textContent = 'No combinations saved yet.'; row.append(cell); list.append(row); return; }
    items.forEach((item) => list.append(makeCombinationRow(item)));
  };
  const timetableCourseColors = (() => {
    try { return JSON.parse(document.querySelector('#timetable-course-colors')?.textContent || '{}'); }
    catch { return {}; }
  })();
  const slotBackground = (assignments) => {
    const colors = [...new Set(assignments.map((item) => timetableCourseColors[String(item.course_id)]).filter(Boolean))];
    if (!colors.length) return '';
    if (colors.length === 1) return colors[0];
    const step = 100 / colors.length;
    return `linear-gradient(135deg, ${colors.map((color, index) => `${color} ${index * step}% ${(index + 1) * step}%`).join(', ')})`;
  };
  const slotFeedback = (message, isError = false) => {
    const alert = document.querySelector('#slot-sweetalert');
    if (!alert) return;
    if (!message) { alert.hidden = true; return; }
    const isSuccess = /^Subject (added|assignment updated|removed)/.test(message);
    alert.querySelector('#slot-alert-title').textContent = isError ? 'Action could not be completed' : (isSuccess ? 'Success' : 'Notice');
    alert.querySelector('#slot-alert-message').textContent = message;
    const icon = alert.querySelector('#slot-alert-icon');
    icon.textContent = isError ? '!' : (isSuccess ? '✓' : 'i');
    icon.classList.toggle('is-error', isError);
    alert.hidden = false;
    alert.querySelector('.slot-sweetalert-ok').focus();
  };
  const updateSlotCell = (cell, assignments) => {
    cell.dataset.assignments = JSON.stringify(assignments);
    cell.classList.toggle('has-assignment', assignments.length > 0);
    cell.style.background = slotBackground(assignments);
    if (assignments.length) cell.textContent = assignments.map((item) => `${item.batch_name1 ? `${item.batch_name1} - ` : ''}${item.short_name}${item.faculty_names ? ` (${item.faculty_names})` : ''}`).join('\n');
    else { cell.replaceChildren(); const empty = document.createElement('span'); empty.className = 'slot-empty-label'; empty.textContent = '+ Assign subjects'; cell.append(empty); }
  };
  const syncSlotCourseHourCounts = (assignments) => {
    const data = document.querySelector('#timetable-slot-course-hour-counts');
    if (!data || !activeSlotCell) return;
    const dayId = String(activeSlotCell.dataset.dayId || ''); const hourId = String(activeSlotCell.dataset.hourId || '');
    const departmentId = String(activeSlotCell.dataset.departmentId || ''); const semesterId = String(activeSlotCell.dataset.semesterId || '');
    const rows = slotJson('#timetable-slot-course-hour-counts').filter((item) => !(String(item.day_id) === dayId && String(item.hour_id) === hourId && String(item.department_id) === departmentId && String(item.semester_id) === semesterId));
    assignments.forEach((item) => rows.push({course_id: item.course_id, day_id: Number(dayId), hour_id: Number(hourId), department_id: Number(departmentId), semester_id: Number(semesterId)}));
    data.textContent = JSON.stringify(rows);
  };
  const renderSlotAssignments = (assignments) => {
    const list = document.querySelector('#slot-assignment-list');
    if (list) {
      list.replaceChildren();
      if (!assignments.length) { const empty = document.createElement('p'); empty.className = 'muted slot-assignment-empty'; empty.textContent = 'No subjects assigned yet.'; list.append(empty); }
      assignments.forEach((assignment) => {
        const row = document.createElement('div'); row.className = 'slot-assignment-row';
        const label = document.createElement('span'); label.className = 'slot-assignment-label';
        label.textContent = `${assignment.course_code} · ${assignment.course_name}${assignment.batch_name1 ? ` — ${assignment.batch_name1} · ${assignment.batch_name2}` : ' — No batch'}${assignment.faculty_names ? ` · Faculty: ${assignment.faculty_names}` : ''}`; row.append(label);
        const form = slotForm();
        const isAdmin = form?.dataset.userRole === 'admin';
        const ownsAssignment = assignment.assigned_by_user_id !== null && Number(assignment.assigned_by_user_id) === Number(form?.dataset.currentUserId || 0);
        if (isAdmin || ownsAssignment) {
          const actions = document.createElement('div'); actions.className = 'slot-assignment-actions';
          const edit = document.createElement('button'); edit.type = 'button'; edit.className = 'slot-assignment-edit'; edit.dataset.assignmentId = String(assignment.assignment_id); edit.textContent = 'Edit'; actions.append(edit);
          const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'slot-assignment-remove'; remove.dataset.assignmentId = String(assignment.assignment_id); remove.textContent = 'Remove'; remove.setAttribute('aria-label', 'Remove ' + assignment.course_code + (assignment.batch_name1 ? ', ' + assignment.batch_name1 : '')); actions.append(remove); row.append(actions);
        } else {
          const owner = document.createElement('span'); owner.className = 'slot-assignment-owner'; owner.textContent = assignment.assigned_by_name ? 'Added by ' + assignment.assigned_by_name : 'Added by Unknown user'; row.append(owner);
        } list.append(row);
      });
    }
    if (activeSlotCell) updateSlotCell(activeSlotCell, assignments);
  };
  const sendSlotMutation = async (action, assignmentId = null) => {
    const form = slotForm(); if (!form || !activeSlotCell || form.dataset.submitting === 'true') return;
    form.dataset.submitting = 'true'; form.setAttribute('aria-busy', 'true');
    const addButton = form.querySelector('button[type=submit]'); if (addButton) { addButton.disabled = true; addButton.dataset.label = addButton.textContent; addButton.textContent = action === 'add' ? 'Adding…' : 'Updating…'; }
    const removeButtons = [...document.querySelectorAll('.slot-assignment-remove')]; removeButtons.forEach((button) => { button.disabled = true; });
    try {
      const data = new FormData(form); data.set('action', action); if (assignmentId !== null) data.set('assignment_id', assignmentId);
      const endpoint = new URL(form.getAttribute('action'), document.baseURI);
      const response = await fetch(endpoint.href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.error || 'Could not update this slot.');
      syncSlotCourseHourCounts(result.assignments || []);
      renderSlotAssignments(result.assignments || []);
      if (action === 'add' || action === 'update') { const subject = form.querySelector('#slot-course-select'); const batch = form.querySelector('#slot-batch-select'); const faculty = form.querySelector('#slot-faculty-select'); subject.value = ''; batch.value = 'none'; if (faculty) [...faculty.options].forEach((option) => { option.selected = false; }); form.dataset.editingAssignmentId = ''; const submit = form.querySelector('button[type=submit]'); submit.textContent = 'Add'; delete submit.dataset.label; form.querySelector('.slot-edit-cancel').hidden = true; if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(subject).trigger('change.select2'); window.jQuery(batch).val('none').trigger('change.select2'); if (faculty) window.jQuery(faculty).val(null).trigger('change.select2'); } const hoursInfo = document.querySelector('#slot-course-hours-info'); if (hoursInfo) hoursInfo.hidden = true; slotFeedback(action === 'update' ? 'Subject assignment updated.' : 'Subject added. You can add another subject.'); }
      else slotFeedback('Subject removed.');
    } catch (error) { slotFeedback(error.message || 'The server could not be reached. Please try again.', true); }
    finally { form.dataset.submitting = 'false'; form.removeAttribute('aria-busy'); if (addButton) { addButton.disabled = false; addButton.textContent = addButton.dataset.label || 'Add'; delete addButton.dataset.label; } removeButtons.forEach((button) => { if (button.isConnected) button.disabled = false; }); }
  };
  const initializeSelect2 = (root = document) => {
    if (!window.jQuery || !window.jQuery.fn.select2) return;
    root.querySelectorAll('select.js-select2').forEach((element) => {
      if (element.closest('[hidden]')) return;
      if (window.jQuery(element).hasClass('select2-hidden-accessible')) return;
      const modal = element.closest('.timetable-modal');
      window.jQuery(element).select2({
        width: '100%',
        placeholder: element.dataset.placeholder || 'Select an option',
        minimumResultsForSearch: 0,
        ...(modal ? { dropdownParent: window.jQuery(modal) } : {})
      });

    });
  };

  const initializeSlotDropdownFocus = () => {
    if (document.documentElement.dataset.slotFocusClickBound === 'true') return;
    document.documentElement.dataset.slotFocusClickBound = 'true';
    document.addEventListener('click', (event) => {
      if (!(event.target instanceof Element) || !event.target.closest('.timetable-slot-card .select2-selection')) return;
      let attempts = 0;
      const focusOpenSearch = () => {
        const input = document.querySelector('.timetable-slot-card .select2-container--open .select2-search__field')
          || document.querySelector('.select2-container--open .select2-search__field');
        if (input && input.isConnected && input.getClientRects().length) {
          input.focus({ preventScroll: true });
          if (document.activeElement === input) return;
        }
        if (attempts++ < 35) window.setTimeout(focusOpenSearch, 20);
      };
      window.setTimeout(focusOpenSearch, 60);
    });
  };

  const initializeCourseCategoryFilter = (root = document) => {
    const revision = root.querySelector('#course-revision');
    const category = root.querySelector('#course-category-select');
    if (!revision || !category) return;
    const filterCategories = () => {
      let selectedIsVisible = false;
      [...category.options].forEach((option) => {
        if (!option.value) return;
        const visible = !revision.value || option.dataset.revision === revision.value;
        option.hidden = !visible;
        option.disabled = !visible;
        if (visible && option.selected) selectedIsVisible = true;
      });
      if (!selectedIsVisible) category.value = '';
      category.disabled = !revision.value;
      if (window.jQuery && window.jQuery.fn.select2) window.jQuery(category).trigger('change.select2');
    };
    revision.addEventListener('change', filterCategories);
    if (window.jQuery) window.jQuery(revision).on('change.courseCategoryFilter', filterCategories);
    filterCategories();
  };

  const initializeCourseTableFilters = (root = document) => {
    const filters = [...root.querySelectorAll('.js-course-filter')];
    const table = root.querySelector('#courses-table');
    if (!filters.length || !table) return;
    const rows = [...table.querySelectorAll('tbody tr[data-department]')];
    const emptyRow = table.querySelector('.course-filter-empty');
    const count = root.querySelector('#course-visible-count');
    const hoursTotal = table.querySelector('#course-hours-grand-total');
    const applyFilters = () => {
      let visibleCount = 0;
      let visibleHours = 0;
      rows.forEach((row) => {
        const matches = filters.every((filter) => {
          const selected = [...filter.selectedOptions].map((option) => option.value);
          return selected.length === 0 || selected.includes(row.dataset[filter.dataset.filterKey]);
        });
        row.hidden = !matches;
        if (matches) {
          visibleCount += 1;
          visibleHours += Number.parseFloat(row.cells[9]?.textContent || '0') || 0;
          const numberCell = row.querySelector('.course-row-number');
          if (numberCell) numberCell.textContent = String(visibleCount);
        }
      });
      if (emptyRow) emptyRow.hidden = visibleCount > 0;
      if (count) count.textContent = String(visibleCount);
      if (hoursTotal) hoursTotal.textContent = String(Number(visibleHours.toFixed(2)));
    };
    filters.forEach((filter) => {
      filter.addEventListener('change', applyFilters);
      if (window.jQuery) window.jQuery(filter).on('change.courseTableFilter', applyFilters);
    });
    applyFilters();
  };

  const initializeTimetableGridFilters = (root = document) => {
    const filters = [...root.querySelectorAll('.timetable-grid-filters [data-grid-filter]')];
    const table = root.querySelector('.timetable-grid');
    if (!filters.length || !table) return;
    const rows = [...table.querySelectorAll('tbody tr[data-day-id]')];
    const applyFilters = () => {
      const selectedByType = new Map();
      filters.forEach((filter) => {
        if (!selectedByType.has(filter.dataset.gridFilter)) selectedByType.set(filter.dataset.gridFilter, new Set());
        if (filter.checked) selectedByType.get(filter.dataset.gridFilter).add(filter.value);
      });
      rows.forEach((row) => {
        row.hidden = [...selectedByType.entries()].some(([type, selected]) => !selected.has(row.dataset[`${type}Id`]));
        const dayCell = row.querySelector('.timetable-day');
        const departmentCell = row.querySelector('.timetable-department');
        dayCell.hidden = false;
        dayCell.rowSpan = 1;
        departmentCell.hidden = false;
        departmentCell.rowSpan = 1;
      });
      const dayGroups = new Map();
      const departmentGroups = new Map();
      rows.filter((row) => !row.hidden).forEach((row) => {
        const dayKey = row.dataset.dayId;
        const dayCell = row.querySelector('.timetable-day');
        if (dayGroups.has(dayKey)) {
          const group = dayGroups.get(dayKey);
          dayCell.hidden = true;
          group.cell.rowSpan += 1;
          group.count += 1;
        } else dayGroups.set(dayKey, { cell: dayCell, count: 1 });

        const departmentKey = `${dayKey}:${row.dataset.departmentId}`;
        const departmentCell = row.querySelector('.timetable-department');
        if (departmentGroups.has(departmentKey)) {
          const group = departmentGroups.get(departmentKey);
          departmentCell.hidden = true;
          group.cell.rowSpan += 1;
        } else departmentGroups.set(departmentKey, { cell: departmentCell });
      });
      dayGroups.forEach(({ cell, count }) => {
        const watermark = cell.querySelector('.day-watermarks');
        watermark.replaceChildren();
        watermark.style.gridTemplateRows = `repeat(${count}, minmax(48px, 1fr))`;
        for (let index = 0; index < count * 2; index += 1) {
          const span = document.createElement('span');
          span.textContent = cell.dataset.watermark || '';
          watermark.append(span);
        }
      });
    };
    filters.forEach((filter) => filter.addEventListener('change', applyFilters));
    applyFilters();
  };

  const initializeWidgets = (root = document) => {
    initializeSelect2(root);
    initializeGeneratedFacultyFilters(root);
    initializeFacultyAvailabilityFilters(root);
    initializeSlotDropdownFocus(root);
    initializeCourseCategoryFilter(root);
    initializeCourseTableFilters(root);
    initializeTimetableGridFilters(root);
  };
  const initializeGeneratedFacultyFilters = (root = document) => {
    const table = root.querySelector?.('#generated-faculty-table');
    if (!table || table.dataset.filtersBound === 'true') return;
    table.dataset.filtersBound = 'true';
    const departmentFilter = root.querySelector('#faculty-filter-departments');
    const designationFilter = root.querySelector('#faculty-filter-designations');
    const selectVisible = root.querySelector('#select-visible-faculty');
    const rows = [...table.querySelectorAll('tbody tr')];
    const values = (select) => {
      if (!select) return [];
      const selected = window.jQuery ? window.jQuery(select).val() : [...select.selectedOptions].map((option) => option.value);
      return (Array.isArray(selected) ? selected : selected ? [selected] : []).map(String);
    };
    const apply = () => {
      const departments = values(departmentFilter);
      const designations = values(designationFilter);
      rows.forEach((row) => {
        row.hidden = (departments.length > 0 && !departments.includes(String(row.dataset.department)))
          || (designations.length > 0 && !designations.includes(String(row.dataset.designation)));
      });
    };
    [departmentFilter, designationFilter].forEach((filter) => {
      filter?.addEventListener('change', apply);
      if (window.jQuery) window.jQuery(filter).on('change select2:select select2:unselect', apply);
    });
    selectVisible?.addEventListener('change', () => rows.filter((row) => !row.hidden).forEach((row) => {
      const checkbox = row.querySelector('input[type="checkbox"]');
      if (checkbox) checkbox.checked = selectVisible.checked;
    }));
    apply();
  };
  const initializeFacultyAvailabilityFilters = (root = document) => {
    const dataElement = root.querySelector?.('#faculty-availability-data');
    const results = root.querySelector?.('#free-faculty-results');
    if (!dataElement || !results || results.dataset.filtersBound === 'true') return;
    results.dataset.filtersBound = 'true';
    let data;
    try { data = JSON.parse(dataElement.textContent || '{}'); }
    catch { results.innerHTML = '<tr><td colspan="5">Could not read the saved faculty snapshot.</td></tr>'; return; }
    const filters = {
      days: root.querySelector('#free-faculty-days'),
      hours: root.querySelector('#free-faculty-hours'),
      departments: root.querySelector('#free-faculty-departments'),
      designations: root.querySelector('#free-faculty-designations'),
    };
    const count = root.querySelector('#free-faculty-count');
    const selected = (select) => {
      const current = window.jQuery ? window.jQuery(select).val() : [...(select?.selectedOptions || [])].map((option) => option.value);
      return new Set((Array.isArray(current) ? current : current ? [current] : []).map(String));
    };
    const render = () => {
      const days = selected(filters.days);
      const hours = selected(filters.hours);
      const departments = selected(filters.departments);
      const designations = selected(filters.designations);
      const engagedFaculty = new Set();
      (data.assignments || []).forEach((assignment) => {
        const dayMatches = !days.size || days.has(String(assignment.day_id));
        const hourMatches = !hours.size || hours.has(String(assignment.hour_id));
        if (!dayMatches || !hourMatches) return;
        (assignment.faculty_ids || []).forEach((id) => engagedFaculty.add(String(id)));
      });
      const freeFaculty = (data.roster || []).filter((person) =>
        !engagedFaculty.has(String(person.id))
        && (!departments.size || departments.has(String(person.department_id)))
        && (!designations.size || designations.has(String(person.designation_id)))
      );
      results.replaceChildren();
      if (!freeFaculty.length) {
        const row = document.createElement('tr');
        const cell = document.createElement('td');
        cell.colSpan = 5; cell.className = 'muted'; cell.textContent = 'No free faculty match these filters.';
        row.append(cell); results.append(row);
      } else {
        freeFaculty.forEach((person, index) => {
          const row = document.createElement('tr');
          [index + 1, person.faculty_name, person.faculty_short_name, `${person.department_name} · ${person.department_code}`, person.designation_name].forEach((value) => {
            const cell = document.createElement('td'); cell.textContent = String(value ?? ''); row.append(cell);
          });
          results.append(row);
        });
      }
      if (count) count.textContent = `${freeFaculty.length} free ${freeFaculty.length === 1 ? 'faculty member' : 'faculty members'}`;
    };
    Object.values(filters).forEach((filter) => {
      filter?.addEventListener('change', render);
      if (window.jQuery) window.jQuery(filter).on('change select2:select select2:unselect', render);
    });
    root.querySelector('#print-free-faculty')?.addEventListener('click', () => window.print());
    render();
  };
  document.addEventListener('DOMContentLoaded', () => {
    initializeWidgets();
    if (window.jQuery) window.jQuery(document).on('change', '#slot-course-faculty-combination', function () {
      const combination = savedCourseFacultyCombinations().find((item) => String(item.id) === String(this.value));
      if (!combination) return;
      const form = slotForm();
      const subject = form?.querySelector('#slot-course-select');
      const faculty = form?.querySelector('#slot-faculty-select');
      if (!subject || !faculty) return;
      subject.value = String(combination.course_id);
      const facultyIds = (combination.faculty_ids || []).map(String);
      [...faculty.options].forEach((option) => { option.selected = facultyIds.includes(option.value); });
      window.jQuery(subject).val(String(combination.course_id)).trigger('change');
      window.jQuery(faculty).val(facultyIds).trigger('change');
    });
    const comboForm = document.querySelector('#timetable-combo-form');
    comboForm?.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!comboForm.reportValidity()) return;
      const button = comboForm.querySelector('#timetable-combo-save'); const status = comboForm.querySelector('#timetable-combo-status');
      button.disabled = true; button.dataset.originalLabel = button.textContent; button.textContent = 'Saving…'; status.textContent = '';
      try {
        const response = await fetch(comboForm.getAttribute('action'), {method:'POST',body:new FormData(comboForm),credentials:'same-origin',headers:{'X-Requested-With':'fetch','Accept':'application/json'}});
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error(result.message || 'Could not save the combination.');
        const items = savedCourseFacultyCombinations(); const index = items.findIndex((item) => Number(item.id) === Number(result.item.id));
        if (index < 0) items.push(result.item); else items[index] = result.item;
        items.sort((a,b) => (a.department_label+a.semester_label+a.course_code+a.combination_code).localeCompare(b.department_label+b.semester_label+b.course_code+b.combination_code));
        syncSavedCourseFacultyCombinations(items); renderTimetableCombinationList(items);
        const id = comboForm.querySelector('#timetable-combo-id'); id.value = '';
        comboForm.reset();
        if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(comboForm.querySelector('#timetable-combo-course')).val('').trigger('change'); window.jQuery(comboForm.querySelector('#timetable-combo-faculty')).val(null).trigger('change'); }
        comboForm.querySelector('#timetable-combo-cancel').hidden = true;
        status.textContent = result.message; status.className = 'success';
      } catch (error) { status.textContent = error.message || 'Could not save the combination.'; status.className = 'alert'; }
      finally { button.disabled = false; button.textContent = comboForm.querySelector('#timetable-combo-id').value ? 'Update Combination' : 'Save Combination'; delete button.dataset.originalLabel; }
    });
    comboForm?.querySelector('#timetable-combo-cancel')?.addEventListener('click', () => {
      comboForm.reset(); comboForm.querySelector('#timetable-combo-id').value = '';
      if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(comboForm.querySelector('#timetable-combo-course')).val('').trigger('change'); window.jQuery(comboForm.querySelector('#timetable-combo-faculty')).val(null).trigger('change'); }
      comboForm.querySelector('#timetable-combo-save').textContent = 'Save Combination'; comboForm.querySelector('#timetable-combo-cancel').hidden = true;
    });
  });
  document.addEventListener('click', (event) => {
    if (event.target.closest('.slot-sweetalert-ok') || event.target.closest('.slot-sweetalert-backdrop')) { slotFeedback(''); return; }
    if (event.target.closest('.timetable-individual-timetable-open')) { openFacultyTimetablePicker(); return; }
    const facultyTimetableOpener = event.target.closest('.slot-faculty-timetable-open');
    if (facultyTimetableOpener) { openFacultyIndividualTimetable(facultyTimetableOpener.dataset.facultyId); return; }
    const clearButton = event.target.closest('.timetable-bulk-clear');
    if (clearButton) {
      const scope = clearButton.dataset.bulkClear;
      const label = clearButton.dataset.clearLabel || (scope === 'row' ? 'this row' : 'this hour column');
      if (!window.confirm(`Clear all removable assignments in ${label}?`)) return;
      const modalForm = slotForm();
      const csrf = modalForm?.querySelector('input[name="csrf"]')?.value || '';
      const data = new FormData(); data.set('csrf', csrf); data.set('action', scope === 'row' ? 'clear_row' : 'clear_column');
      data.set('timetable_id', clearButton.dataset.timetableId || '');
      if (scope === 'row') { data.set('day_id', clearButton.dataset.dayId); data.set('department_id', clearButton.dataset.departmentId); data.set('semester_id', clearButton.dataset.semesterId); }
      else data.set('hour_id', clearButton.dataset.hourId);
      const originalText = clearButton.textContent; clearButton.disabled = true; clearButton.textContent = 'Clearing…';
      fetch(new URL('timetable-slot-course.php', document.baseURI).href, { method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(async (response) => { const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.error || 'Could not clear assignments.'); return result; })
        .then((result) => {
          const removedIds = new Set((result.cleared || []).map((item) => String(item.assignment_id)));
          document.querySelectorAll('.timetable-slot-open').forEach((cell) => {
            const assignments = JSON.parse(cell.dataset.assignments || '[]');
            if (assignments.some((item) => removedIds.has(String(item.assignment_id)))) updateSlotCell(cell, assignments.filter((item) => !removedIds.has(String(item.assignment_id))));
          });
          let message = `${result.cleared_count} assignment${result.cleared_count === 1 ? '' : 's'} cleared from ${label}.`;
          if (result.preserved_count > 0) message += ` ${result.preserved_count} matching assignment${result.preserved_count === 1 ? ' belongs' : 's belong'} to another user and can only be cleared by an administrator.`;
          else if (result.cleared_count === 0) message = `No assignments were found in ${label}.`;
          window.alert(message);
        })
        .catch((error) => window.alert(error.message || 'Could not clear assignments. Please try again.'))
        .finally(() => { if (clearButton.isConnected) { clearButton.disabled = false; clearButton.textContent = originalText; } });
      return;
    }
    const combinationOpener = event.target.closest('.timetable-course-faculty-combinations-open');
    if (combinationOpener) {
      const modal = document.querySelector('#timetable-course-faculty-combinations-modal');
      if (modal) { modal.hidden = false; initializeWidgets(modal); modal.querySelector('.timetable-modal-close').focus(); }
      return;
    }
    const combinationEdit = event.target.closest('.timetable-combo-edit');
    if (combinationEdit) {
      const form = document.querySelector('#timetable-combo-form');
      if (!form) return;
      const item = JSON.parse(combinationEdit.dataset.item || '{}');
      form.querySelector('#timetable-combo-id').value = String(item.id || '');
      form.querySelector('#timetable-combo-code').value = item.combination_code || '';
      if (window.jQuery && window.jQuery.fn.select2) {
        window.jQuery(form.querySelector('#timetable-combo-course')).val(String(item.course_id || '')).trigger('change');
        window.jQuery(form.querySelector('#timetable-combo-faculty')).val((item.faculty_ids || []).map(String)).trigger('change');
      }
      form.querySelector('#timetable-combo-save').textContent = 'Update Combination'; form.querySelector('#timetable-combo-cancel').hidden = false;
      form.scrollIntoView({behavior:'smooth',block:'start'}); return;
    }
    const combinationDelete = event.target.closest('.timetable-combo-delete');
    if (combinationDelete) {
      if (!window.confirm('Delete this course-faculty combination?')) return;
      const form = document.querySelector('#timetable-combo-form'); if (!form) return;
      combinationDelete.disabled = true;
      const data = new FormData(); data.set('csrf',form.querySelector('[name="csrf"]').value); data.set('action','delete'); data.set('id',combinationDelete.dataset.id); data.set('timetable_id',form.querySelector('[name="timetable_id"]')?.value || '');
      fetch(form.getAttribute('action'),{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'fetch','Accept':'application/json'}})
        .then(async (response) => { const result = await response.json(); if (!response.ok || !result.success) throw new Error(result.message || 'Could not delete the combination.'); return result; })
        .then((result) => {
          const items = savedCourseFacultyCombinations().filter((item) => String(item.id) !== String(combinationDelete.dataset.id));
          syncSavedCourseFacultyCombinations(items); renderTimetableCombinationList(items);
          const status = form.querySelector('#timetable-combo-status'); status.textContent = result.message; status.className = 'success';
        })
        .catch((error) => { combinationDelete.disabled = false; const status = form.querySelector('#timetable-combo-status'); status.textContent = error.message || 'Could not delete the combination.'; status.className = 'alert'; });
      return;
    }
    const summaryOpener = event.target.closest('.timetable-summary-open');
    if (summaryOpener) {
      const modal = document.querySelector('#timetable-summary-modal');
      if (modal) {
        reportViewSeen.summary.clear();
        modal.hidden = false;
        initializeWidgets(modal);
        const department = modal.querySelector('#summary-department-select');
        department.value = '';
        if (window.jQuery && window.jQuery.fn.select2) window.jQuery(department).trigger('change');
        renderTimetableSummary('');
        modal.querySelector('.timetable-modal-close').focus();
      }
      return;
    }
    const workloadOpener = event.target.closest('.timetable-workload-open');
    if (workloadOpener) {
      const modal = document.querySelector('#timetable-workload-modal');
      if (modal) {
        reportViewSeen.workload.clear();
        modal.hidden = false;
        initializeWidgets(modal);
        const faculty = modal.querySelector('#workload-faculty-select');
        faculty.value = '';
        if (window.jQuery && window.jQuery.fn.select2) window.jQuery(faculty).trigger('change');
        renderFacultyWorkload('');
        modal.querySelector('.timetable-modal-close').focus();
      }
      return;
    }
    const courseHoursOpener = event.target.closest('.timetable-course-hours-open');
    if (courseHoursOpener) {
      const modal = document.querySelector('#timetable-course-hours-modal');
      if (modal) {
        reportViewSeen.courseHours.clear();
        modal.hidden = false;
        initializeWidgets(modal);
        const department = modal.querySelector('#course-hours-department-select');
        const course = modal.querySelector('#course-hours-course-select');
        department.value = ''; course.replaceChildren();
        const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Select a department first'; course.append(placeholder);
        if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(department).val('').trigger('change'); window.jQuery(course).val('').trigger('change'); }
        renderCourseHours('', '');
        modal.querySelector('.timetable-modal-close').focus();
      }
      return;
    }
    const opener = event.target.closest('.timetable-slot-open');
    if (opener) {
      const modal = document.querySelector('#timetable-slot-modal');
      if (!modal) return;
      activeSlotCell = opener;
      const form = modal.querySelector('.timetable-slot-assignment-form');
      const select = modal.querySelector('#slot-course-select');
      const values = { 'slot-timetable-id': opener.dataset.timetableId, 'slot-day-id': opener.dataset.dayId, 'slot-hour-id': opener.dataset.hourId, 'slot-department-id': opener.dataset.departmentId, 'slot-semester-id': opener.dataset.semesterId };
      Object.entries(values).forEach(([id, value]) => { modal.querySelector('#' + id).value = value || ''; });
      const slotDetails = modal.querySelector('#slot-modal-details');
      slotDetails.replaceChildren();
      [opener.dataset.dayName2, opener.dataset.hourName2, opener.dataset.departmentCode, opener.dataset.semesterCode].forEach((value, index) => {
        if (index) slotDetails.append(document.createTextNode(' - '));
        const strong = document.createElement('strong'); strong.textContent = value || ''; slotDetails.append(strong);
      });
      const assignments = JSON.parse(opener.dataset.assignments || '[]');
      const options = JSON.parse(document.querySelector('#timetable-course-options')?.textContent || '[]');
      const facultyOptions = JSON.parse(document.querySelector('#timetable-faculty-options')?.textContent || '[]');
      const combinationSelect = modal.querySelector('#slot-course-faculty-combination');
      if (combinationSelect) {
        combinationSelect.replaceChildren();
        const combinationPlaceholder = document.createElement('option'); combinationPlaceholder.value = ''; combinationPlaceholder.textContent = 'Select a combination'; combinationSelect.append(combinationPlaceholder);
        savedCourseFacultyCombinations().filter((item) => String(item.department_id) === opener.dataset.departmentId && String(item.semester_id) === opener.dataset.semesterId).forEach((item) => {
          const option = document.createElement('option'); option.value = String(item.id); option.textContent = item.combination_code + ' · ' + item.course_code; combinationSelect.append(option);
        });
      }
      select.replaceChildren();
      const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = 'Select a subject'; select.append(placeholder);
      options.filter((course) => String(course.department_id) === opener.dataset.departmentId && String(course.semester_id) === opener.dataset.semesterId).forEach((course) => {
        const option = document.createElement('option'); option.value = String(course.id);
        const shortName = course.course_short_name ? ' · ' + course.course_short_name : '';
        option.textContent = course.course_code + ' · ' + course.course_name + shortName; select.append(option);
      });
      const faculty = modal.querySelector('#slot-faculty-select');
      faculty.replaceChildren();
      facultyOptions.forEach((person) => {
        const option = document.createElement('option'); option.value = String(person.id); option.textContent = person.faculty_name + (person.faculty_short_name ? ' · ' + person.faculty_short_name : ''); faculty.append(option);
      });
      const batch = modal.querySelector('#slot-batch-select'); batch.value = 'none';
      form.dataset.editingAssignmentId = '';
      form.querySelector('button[type=submit]').textContent = 'Add';
      form.querySelector('.slot-edit-cancel').hidden = true;
      modal.hidden = false; initializeWidgets(modal); ensureSlotInfoPanels();
      if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(select).val('').trigger('change'); window.jQuery(batch).val('none').trigger('change'); window.jQuery(faculty).val(null).trigger('change'); if (combinationSelect) window.jQuery(combinationSelect).val('').trigger('change'); }
      renderSlotAssignments(assignments); slotFeedback('');
      modal.querySelector('.timetable-modal-close').focus(); return;
    }
    const removeAssignment = event.target.closest('.slot-assignment-remove');
    if (removeAssignment) {
      const assignmentLabel = removeAssignment.closest('.slot-assignment-row')?.querySelector('.slot-assignment-label')?.textContent?.trim() || 'this assigned course';
      if (window.confirm(`Remove ${assignmentLabel} from this timetable slot?`)) sendSlotMutation('delete', removeAssignment.dataset.assignmentId);
      return;
    }
    const editAssignment = event.target.closest('.slot-assignment-edit');
    if (editAssignment) {
      const assignmentId = editAssignment.dataset.assignmentId;
      const assignment = JSON.parse(activeSlotCell?.dataset.assignments || '[]').find((item) => String(item.assignment_id) === String(assignmentId));
      const form = slotForm();
      if (!assignment || !form) return;
      const subject = form.querySelector('#slot-course-select'); const batch = form.querySelector('#slot-batch-select'); const faculty = form.querySelector('#slot-faculty-select');
      subject.value = String(assignment.course_id); batch.value = assignment.batch_id === null ? 'none' : String(assignment.batch_id);
      const selectedFacultyIds = (assignment.faculty_ids || '').split(',').filter(Boolean).map(String);
      [...faculty.options].forEach((option) => { option.selected = selectedFacultyIds.includes(option.value); });
      form.dataset.editingAssignmentId = String(assignment.assignment_id);
      const submit = form.querySelector('button[type=submit]'); submit.textContent = 'Update';
      form.querySelector('.slot-edit-cancel').hidden = false;
      if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(subject).val(String(assignment.course_id)).trigger('change'); window.jQuery(batch).val(assignment.batch_id === null ? 'none' : String(assignment.batch_id)).trigger('change'); window.jQuery(faculty).val(selectedFacultyIds).trigger('change'); }
      slotFeedback('Edit the subject, batch, or faculty and select Update.');
      subject.closest('label')?.scrollIntoView({ block: 'nearest' });
      return;
    }
    if (event.target.closest('.slot-edit-cancel')) {
      const form = slotForm(); if (!form) return;
      form.dataset.editingAssignmentId = '';
      form.querySelector('#slot-course-select').value = '';
      form.querySelector('#slot-batch-select').value = 'none';
      const faculty = form.querySelector('#slot-faculty-select'); [...faculty.options].forEach((option) => { option.selected = false; });
      form.querySelector('button[type=submit]').textContent = 'Add';
      event.target.closest('.slot-edit-cancel').hidden = true;
      if (window.jQuery && window.jQuery.fn.select2) { window.jQuery(form.querySelector('#slot-course-select')).val('').trigger('change'); window.jQuery(form.querySelector('#slot-batch-select')).val('none').trigger('change'); window.jQuery(faculty).val(null).trigger('change'); }
      slotFeedback(''); return;
    }
    if (event.target.closest('[data-modal-close]')) {
      const modal = event.target.closest('.timetable-modal');
      if (modal) modal.hidden = true;
    }
  });
  const renderTimetableSummary = (departmentId) => {
    const body = document.querySelector('#timetable-summary-body');
    if (!body) return;
    body.replaceChildren();
    const addMessage = (message) => {
      const row = document.createElement('tr');
      const cell = document.createElement('td');
      cell.colSpan = 6;
      cell.className = 'muted';
      cell.textContent = message;
      row.append(cell);
      body.append(row);
    };
    if (!departmentId) { addMessage('Select a department to view its courses.'); return; }
    logReportView('summary_view', departmentId);
    const data = JSON.parse(document.querySelector('#timetable-summary-data')?.textContent || '[]');
    const rows = data.filter((item) => String(item.department_id) === String(departmentId));
    if (!rows.length) { addMessage('No courses are available for this department in the timetable semesters.'); return; }
    rows.forEach((item, index) => {
      const row = document.createElement('tr');
      [String(index + 1), item.semester_full_name, item.course_code, item.course_short_name || '—', String(item.hours_per_week), String(item.hours_assigned)].forEach((value) => {
        const cell = document.createElement('td');
        cell.textContent = value;
        row.append(cell);
      });
      body.append(row);
    });
  };
  const renderFacultyWorkload = (facultyId) => {
    const body = document.querySelector('#faculty-workload-body');
    const total = document.querySelector('#faculty-workload-total');
    if (!body || !total) return;
    body.replaceChildren();
    let grandTotal = 0;
    const addMessage = (message) => {
      const row = document.createElement('tr'); const cell = document.createElement('td');
      cell.colSpan = 6; cell.className = 'muted'; cell.textContent = message; row.append(cell); body.append(row);
    };
    if (!facultyId) { addMessage('Select a faculty member to view workload.'); total.textContent = 'Grand total hours assigned: 0'; return; }
    logReportView('faculty_workload_view', facultyId);
    const data = JSON.parse(document.querySelector('#timetable-workload-data')?.textContent || '[]');
    const rows = data.filter((item) => String(item.faculty_id) === String(facultyId));
    if (!rows.length) { addMessage('No courses are assigned to this faculty member in this timetable.'); total.textContent = 'Grand total hours assigned: 0'; return; }
    rows.forEach((item, index) => {
      const row = document.createElement('tr');
      const assigned = Number(item.assigned_hours) || 0; grandTotal += assigned;
      [String(index + 1), item.course_code, item.course_name, item.semester_full_name, item.assigned_slots || '—', String(assigned)].forEach((value, columnIndex) => {
        const cell = document.createElement('td'); cell.textContent = value ?? ''; if (columnIndex === 4) cell.className = 'faculty-workload-slots'; row.append(cell);
      });
      body.append(row);
    });
    total.textContent = `Grand total hours assigned: ${grandTotal}`;
  };
  const populateCourseHoursCourses = (departmentId) => {
    const select = document.querySelector('#course-hours-course-select');
    if (!select) return;
    select.replaceChildren();
    const placeholder = document.createElement('option'); placeholder.value = ''; placeholder.textContent = departmentId ? 'Select a course' : 'Select a department first'; select.append(placeholder);
    if (departmentId) {
      const courses = JSON.parse(document.querySelector('#timetable-course-hours-courses')?.textContent || '[]');
      courses.filter((course) => String(course.department_id) === String(departmentId)).forEach((course) => {
        const option = document.createElement('option'); option.value = String(course.course_id);
        option.textContent = `${course.course_code} · ${course.course_name}${course.course_short_name ? ` · ${course.course_short_name}` : ''}`; select.append(option);
      });
    }
    if (window.jQuery && window.jQuery.fn.select2) window.jQuery(select).val('').trigger('change.select2');
  };
  const renderCourseHours = (departmentId, courseId) => {
    const body = document.querySelector('#course-hours-body');
    if (!body) return;
    body.replaceChildren();
    const addMessage = (message) => { const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 7; cell.className = 'muted'; cell.textContent = message; row.append(cell); body.append(row); };
    if (!departmentId || !courseId) { addMessage('Select a department and course to view assigned hours.'); return; }
    logReportView('course_hours_view', departmentId, courseId);
    const data = JSON.parse(document.querySelector('#timetable-course-hours-data')?.textContent || '[]');
    const rows = data.filter((item) => String(item.department_id) === String(departmentId) && String(item.course_id) === String(courseId));
    if (!rows.length) { addMessage('No hours have been assigned to this course in the timetable.'); return; }
    rows.forEach((item, index) => {
      const row = document.createElement('tr');
      [String(index + 1), item.day_name_1, item.hour_name_2 || item.hour_name_1, `${item.time_from} – ${item.time_to}`, item.semester_full_name, item.batch_names || 'No batch', item.faculty_names || '—'].forEach((value) => { const cell = document.createElement('td'); cell.textContent = value ?? ''; row.append(cell); });
      body.append(row);
    });
  };
  document.addEventListener('change', (event) => {
    if (event.target.matches('.js-auto-submit')) event.target.form?.requestSubmit();
    if (event.target.matches('#summary-department-select')) renderTimetableSummary(event.target.value);
    if (event.target.matches('#workload-faculty-select')) renderFacultyWorkload(event.target.value);
    if (event.target.matches('#course-hours-department-select')) { populateCourseHoursCourses(event.target.value); renderCourseHours(event.target.value, ''); }
    if (event.target.matches('#course-hours-course-select')) renderCourseHours(document.querySelector('#course-hours-department-select')?.value || '', event.target.value);
  });
  if (window.jQuery) window.jQuery(document).on('change.timetableSummary', '#summary-department-select', function () {
    renderTimetableSummary(this.value);
  });
  if (window.jQuery) window.jQuery(document).on('change.facultyWorkload', '#workload-faculty-select', function () {
    renderFacultyWorkload(this.value);
  });
  if (window.jQuery) window.jQuery(document).on('change.courseHours', '#course-hours-department-select', function () {
    populateCourseHoursCourses(this.value); renderCourseHours(this.value, '');
  });
  if (window.jQuery) window.jQuery(document).on('change.courseHours', '#course-hours-course-select', function () {
    renderCourseHours(document.querySelector('#course-hours-department-select')?.value || '', this.value);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const alert = document.querySelector('#slot-sweetalert:not([hidden])');
      if (alert) { slotFeedback(''); return; }
      document.querySelectorAll('.timetable-modal:not([hidden])').forEach((modal) => { modal.hidden = true; });
    }
  });
  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && form.matches('.timetable-slot-assignment-form')) {
      event.preventDefault(); event.stopPropagation(); event.stopImmediatePropagation();
      const subject = form.querySelector('#slot-course-select');
      if (!subject.value) { slotFeedback('Select a subject before adding it to this timetable slot.', true); return; }
      const editingId = form.dataset.editingAssignmentId || ''; sendSlotMutation(editingId ? 'update' : 'add', editingId || null);
      return;
    }
    if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'GET').toUpperCase() !== 'POST' || event.defaultPrevented || form.dataset.submitting === 'true') return;
    event.preventDefault();
    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');
    const submitButtons = [...form.querySelectorAll('button[type="submit"], input[type="submit"]')];
    submitButtons.forEach((button) => { button.dataset.wasDisabled = button.disabled ? 'true' : 'false'; button.disabled = true; });
    const activeButton = event.submitter instanceof HTMLButtonElement || event.submitter instanceof HTMLInputElement ? event.submitter : null;
    if (activeButton) {
      activeButton.dataset.originalLabel = activeButton instanceof HTMLInputElement ? activeButton.value : activeButton.textContent;
      activeButton.dataset.loading = 'true';
      const originalLabel = (activeButton instanceof HTMLInputElement ? activeButton.value : activeButton.textContent).trim();
      const action = /^delete/i.test(originalLabel) ? 'Deleting' : 'Saving';
      const subject = originalLabel.replace(/^(Save|Update|Delete)\s*/i, '').trim();
      const loadingLabel = subject ? `${action} ${subject.toLowerCase()}…` : `${action}…`;
      if (activeButton instanceof HTMLInputElement) activeButton.value = loadingLabel;
      else activeButton.textContent = loadingLabel;
    }
    const showError = (message) => {
      let alert = form.querySelector('.form-request-error');
      if (!alert) { alert = document.createElement('div'); alert.className = 'alert form-request-error'; alert.setAttribute('role', 'alert'); form.prepend(alert); }
      alert.textContent = message;
    };
    try {
      const data = new FormData(form);
      if (event.submitter?.name && !data.has(event.submitter.name)) data.append(event.submitter.name, event.submitter.value);
      const actionAttribute = form.getAttribute('action');
      const targetUrl = actionAttribute ? new URL(actionAttribute, document.baseURI).href : window.location.href;
      const response = await fetch(targetUrl, {
        method: 'POST', body: data, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'text/html' }
      });
      const html = await response.text();
      const nextDocument = new DOMParser().parseFromString(html, 'text/html');
      const currentMain = document.querySelector('.app-main');
      const nextMain = nextDocument.querySelector('.app-main');
      if (!response.ok && !nextMain) {
        showError(html.trim() || 'The request could not be completed. Please try again.');
      } else if (currentMain && nextMain) {
        currentMain.replaceWith(nextMain);
        initializeWidgets(nextMain);
        document.title = nextDocument.title || document.title;
      } else {
        document.body.innerHTML = nextDocument.body.innerHTML;
        initializeWidgets(document);
        document.title = nextDocument.title || document.title;
      }
      if (response.url) {
        const destination = new URL(response.url, window.location.href);
        if (destination.href !== window.location.href) window.history.pushState({}, '', destination.href);
      }
    } catch (error) {
      showError('Could not save because the server could not be reached. Check the connection and try again.');
    } finally {
      if (form.isConnected) {
        form.dataset.submitting = 'false';
        form.removeAttribute('aria-busy');
        submitButtons.forEach((button) => { button.disabled = button.dataset.wasDisabled === 'true'; delete button.dataset.wasDisabled; });
        if (activeButton) {
          if (activeButton instanceof HTMLInputElement) activeButton.value = activeButton.dataset.originalLabel || 'Save';
          else activeButton.textContent = activeButton.dataset.originalLabel || 'Save';
          delete activeButton.dataset.originalLabel;
          delete activeButton.dataset.loading;
        }
      }
    }
  });
})();
