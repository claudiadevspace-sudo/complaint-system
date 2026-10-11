<?php
/**
 * Program dropdown, grouped by department (official lists from
 * Super Admin → Settings → Programs). Used by Register and by the Super
 * Admin account form, so programs are always spelled the same way —
 * routing to the right Program Coordinator depends on it.
 *
 * Set before including:
 *   $programSelectId       — id/name of the <select> (e.g. 'program')
 *   $programSelectValue    — current value (any spelling: "IT" matches "Information Technology")
 *   $programSelectDept     — department the value belongs to (for matching), may be ''
 *   $programSelectOnly     — list only this department's programs ('' = all departments)
 *   $programSelectFilterBy — id of the department <select> to follow (app.js hides other
 *                            departments' programs when it changes), or ''
 *   $programSelectRequired — bool
 *   $programSelectEmpty    — text of the empty choice
 */
$selectedProgram = canonicalProgram($programSelectDept, $programSelectValue) ?? '';
$programDepartments = $programSelectOnly !== '' ? [$programSelectOnly] : departments();
?>
<select class="form-select" id="<?php echo $programSelectId; ?>" name="<?php echo $programSelectId; ?>"
        <?php echo $programSelectRequired ? 'required' : ''; ?>
        <?php if ($programSelectFilterBy !== ''): ?>data-program-select data-department-field="<?php echo $programSelectFilterBy; ?>"<?php endif; ?>>
    <option value=""><?php echo htmlspecialchars($programSelectEmpty); ?></option>
    <?php foreach ($programDepartments as $dept): ?>
        <optgroup label="<?php echo $dept; ?>" data-department="<?php echo $dept; ?>">
            <?php foreach (programsFor($dept) as $program): ?>
                <option value="<?php echo htmlspecialchars($program); ?>"
                    <?php echo $program === $selectedProgram && ($programSelectDept === '' || strtoupper($programSelectDept) === $dept) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($program); ?>
                </option>
            <?php endforeach; ?>
        </optgroup>
    <?php endforeach; ?>
</select>
<?php if ($programSelectValue !== '' && $programSelectValue !== null && $selectedProgram === ''): ?>
    <div class="form-text text-warning">Saved as "<?php echo htmlspecialchars((string)$programSelectValue); ?>", which is not on the official list — please choose one.</div>
<?php endif; ?>
