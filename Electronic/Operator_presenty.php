<?php
session_start();
if (!isset($_SESSION['operator_id'])) {
    header("Location: ../includes/index.php");
    exit;
}
// Connect DB
include '../Includes/db_connect.php';
include '../Includes/sidebar.php';
// Load operators (exclude only Inactive operators)
$activeOperators = [];
$operatorQuery = $conn->query("SELECT op_id, name FROM operators WHERE present_status != 'Inactive' ORDER BY CAST(op_id AS UNSIGNED) ASC");
if ($operatorQuery) {
    while ($row = $operatorQuery->fetch_assoc()) {
        $activeOperators[] = $row;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $month = $_POST['month'] ?? '';
    $date = $_POST['date'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $op_id = $_POST['op_id'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $lunch_out = $_POST['lunch_out'] ?? '';
    $lunch_in = $_POST['lunch_in'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $total_hrs = $_POST['total_hrs'] ?? 0;
    $worked_hrs = $_POST['worked_hrs'] ?? 0;
    $target = $_POST['target'] ?? 0;
    $actual = $_POST['actual'] ?? 0;
    $extra = $_POST['extra'] ?? 0;
    $less = $_POST['less'] ?? 0;
    $incentive = $_POST['incentive'] ?? 0;
    $date_shift_id = $_POST['date_shift_id'] ?? '';

    // Get operator name
    $op_name = '';
    $nameQuery = $conn->prepare("SELECT name FROM operators WHERE op_id = ?");
    $nameQuery->bind_param("s", $op_id);
    $nameQuery->execute();
    $nameResult = $nameQuery->get_result();
    if ($nameRow = $nameResult->fetch_assoc()) {
        $op_name = $nameRow['name'];
    }

    try {
        // Check if record already exists for this date, shift, and operator
        $checkStmt = $conn->prepare("SELECT id FROM operator_presence WHERE date = ? AND shift = ? AND op_id = ?");
        $checkStmt->bind_param("sss", $date, $shift, $op_id);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();

        if ($checkResult->num_rows > 0) {
            // Update existing record
            $updateStmt = $conn->prepare("UPDATE operator_presence SET 
                month=?, start_time=?, lunch_out=?, lunch_in=?, end_time=?, 
                total_hrs=?, worked_hrs=?, target=?, actual=?, extra=?, less=?, 
                incentive=?, date_shift_id=?, op_name=?, updated_at=CURRENT_TIMESTAMP 
                WHERE date=? AND shift=? AND op_id=?");
            $updateStmt->bind_param("sssssddddddssss", 
                $month, $start_time, $lunch_out, $lunch_in, $end_time,
                $total_hrs, $worked_hrs, $target, $actual, $extra, $less,
                $incentive, $date_shift_id, $op_name, $date, $shift, $op_id);
            
            if ($updateStmt->execute()) {
                echo "<script>alert('Operator presence updated successfully!');</script>";
            } else {
                echo "<script>alert('Failed to update presence record.');</script>";
            }
        } else {
            // Insert new record
            $insertStmt = $conn->prepare("INSERT INTO operator_presence 
                (month, date, shift, op_id, op_name, start_time, lunch_out, lunch_in, end_time, 
                total_hrs, worked_hrs, target, actual, extra, less, incentive, date_shift_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $insertStmt->bind_param("sssssssssddddddds", 
                $month, $date, $shift, $op_id, $op_name, $start_time, $lunch_out, $lunch_in, $end_time,
                $total_hrs, $worked_hrs, $target, $actual, $extra, $less, $incentive, $date_shift_id);
            
            if ($insertStmt->execute()) {
                echo "<script>alert('Operator presence recorded successfully!');</script>";
            } else {
                echo "<script>alert('Failed to record presence.');</script>";
            }
        }
    } catch (Exception $e) {
        echo "<script>alert('Database error: " . addslashes($e->getMessage()) . "');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- jQuery (Required for Select2) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />

    <meta charset="UTF-8">
    <title>Operator Presence Entry</title>
    <style>
        /* Enhanced Select2 styling - Remove duplicate styles and fix conflicts */
        .select2-container--default .select2-selection--single {
            height: 38px !important;
            border: 1px solid #bbb !important;
            border-radius: 5px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #333 !important;
            padding-left: 12px !important;
            padding-right: 20px !important;
            line-height: 36px !important;
            display: block !important;
        }

        /* Ensure selected text is black and visible */
        .select2-container--default .select2-selection--single .select2-selection__rendered span {
            color: #000 !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered strong {
            color: #333 !important;
        }

        /* Fix any text color issues in the selection */
        .select2-selection__rendered * {
            color: inherit !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px !important;
            right: 10px !important;
        }

        .select2-dropdown {
            border: 1px solid #ced4da !important;
            border-radius: 0.375rem !important;
            z-index: 9999 !important;
            background-color: #ffffff !important; /* Ensure dropdown has white background */
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid rgb(0, 0, 0) !important;
            border-radius: 0.375rem;
            padding: 8px 12px !important;
            font-size: 14px;
        }

        .select2-container--default .select2-results__option {
            padding: 10px 12px !important;
            border-bottom: 1px solidrgb(0, 0, 0);
            background-color:rgba(160, 148, 148, 0.67) !important; /* Ensure white background */
        }

        .select2-container--default .select2-results__option:hover {
            background-color:rgb(139, 152, 165) !important;
        }

        .select2-container--default .select2-results__option--highlighted .operator-id {
            color:rgb(0, 0, 0) !important;
        }

        .select2-container--default .select2-results__option--highlighted .operator-info {
            color: #e6f3ff !important; /* Light blue for highlighted state */
            opacity: 1 !important;
        }

        .operator-option {
            display: block;
            line-height: 1.4;
            padding: 2px 0;
        }

        .operator-id {
            font-weight: bold;
            color: #333 !important;
            font-size: 14px;
        }

        .operator-info {
            font-size: 12px;
            color: #495057 !important; /* Changed from #6c757d to darker color */
            font-style: italic;
            margin-top: 2px;
            opacity: 1 !important; /* Ensure full opacity */
        }

        /* Ensure Select2 container has proper width */
        .select2-container {
            width: 100% !important;
        }

        /* Fix selection display issues */
        .select2-container--default .select2-selection--single .select2-selection__placeholder {
            color: #999 !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #4a90e2 !important;
        }

        .main-content {
            margin-left: 270px;
            padding: 30px 10px 30px 10px;
            min-height: 100vh;
        }

        .presence-form-container {
            background: #fff;
            border-radius: 12px;
            max-width: 1200px;
            margin: 0 auto;
            padding: 30px 40px 20px 40px;
            box-shadow: 0 6px 24px rgba(0, 0, 0, 0.12);
        }

        .presence-form-container h2 {
            text-align: center;
            background: rgba(66, 96, 230, 0.78);
            color: #222;
            padding: 10px 0;
            border-radius: 8px;
            margin-bottom: 30px;
            letter-spacing: 1px;
        }

        .presence-form label {
            font-weight: bold;
            margin-bottom: 4px;
            color: rgb(14, 8, 11);
            font-size: 12px;
            display: block;
        }

        .presence-form input,
        .presence-form select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #bbb;
            border-radius: 4px;
            margin-bottom: 0; /* Remove bottom margin for better row alignment */
            font-size: 13px;
            box-sizing: border-box;
        }

        .presence-form .row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
        }

        .presence-form .row > div {
            flex: 1;
        }

        /* Specific styling for 4-column rows (time fields) */
        .presence-form .row-4 {
            display: flex;
            gap: 12px;
            margin-bottom: 15px;
        }

        .presence-form .row-4 > div {
            flex: 1;
            min-width: 0; /* Allow flex items to shrink */
        }

        .calculated-field {
            background-color: #f8f9fa !important;
        }

        .time-field {
            background-color: #fff !important;
        }

        /* Button styling */
        .presence-form button {
            background: #4a90e2;
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            margin: 0 8px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            min-width: 140px;
        }

        .presence-form button:hover {
            background: #357abd;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .presence-form button[type="reset"] {
            background: #6c757d;
        }

        .presence-form button[type="reset"]:hover {
            background: #5a6268;
        }

        .presence-form button:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .presence-form button:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgba(74, 144, 226, 0.3);
        }

        .button-container {
            text-align: center;
            margin-top: 30px;
            padding: 20px 0;
            border-top: 1px solid #e9ecef;
        }

        /* Add icons to buttons */
     
        /* Responsive design for smaller screens */
        @media (max-width: 1000px) {
            .main-content {
                margin-left: 0;
            }

            .presence-form-container {
                padding: 18px 15px;
            }

            .presence-form .row,
            .presence-form .row-4 {
                flex-direction: column;
                gap: 0;
            }

            .presence-form .row > div,
            .presence-form .row-4 > div {
                flex: 1;
                margin-bottom: 15px;
            }
        }

        @media (max-width: 768px) {
            .presence-form .row-4 {
                flex-direction: column;
            }
            
            .presence-form .row-4 > div {
                width: 100%;
                margin-bottom: 15px;
            }
        }

        /* Ensure proper spacing between form sections */
        .form-section {
            margin-bottom: 20px;
        }
    </style>
</head>

<body>
    <div class="main-content">
        <div class="presence-form-container">
            <h2>OPERATOR PRESENCE ENTRY</h2>
            <form class="presence-form" method="post">
                <!-- Row 1: Basic Information -->
                <div class="form-section">
                    <div class="row">
                        <div>
                            <label for="month">MONTH</label>
                            <input type="text" id="month" name="month" placeholder="e.g. Jun-25" readonly>
                        </div>
                        <div>
                            <label for="date">DATE</label>
                            <input type="date" id="date" name="date" required>
                        </div>
                        <div>
                            <label for="shift">SHIFT</label>
                            <select id="shift" name="shift" required>
                                <option value="">-- Select Shift --</option>
                                <option value="I">I</option>
                                <option value="II">II</option>
                                <option value="III">III</option>
                            </select>
                        </div>
                        <div>
                            <label for="op_id">OP ID</label>
                            <select id="op_id" name="op_id" style="width:100%" required>
                                <option value="">-- Select OP ID --</option>
                                <?php foreach ($activeOperators as $operator): ?>
                                    <option value="<?= htmlspecialchars($operator['op_id']) ?>" 
                                            data-name="<?= htmlspecialchars($operator['name']) ?>">
                                        <?= htmlspecialchars($operator['op_id']) ?> - <?= htmlspecialchars($operator['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Time Information (4 fields in one row) -->
                <div class="form-section">
                    <div class="row-4">
                        <div>
                            <label for="start_time">START TIME</label>
                            <input type="time" id="start_time" name="start_time" class="time-field">
                        </div>
                        <div>
                            <label for="lunch_out">LUNCH OUT</label>
                            <input type="time" id="lunch_out" name="lunch_out" class="time-field">
                        </div>
                        <div>
                            <label for="lunch_in">LUNCH IN</label>
                            <input type="time" id="lunch_in" name="lunch_in" class="time-field">
                        </div>
                        <div>
                            <label for="end_time">END TIME</label>
                            <input type="time" id="end_time" name="end_time" class="time-field">
                        </div>
                    </div>
                </div>

                <!-- Row 3: Hours Calculation (4 fields in one row) -->
                <div class="form-section">
                    <div class="row-4">
                        <div>
                            <label for="total_hrs">TOTAL HRS</label>
                            <input type="number" step="0.1" id="total_hrs" name="total_hrs" 
                                   class="calculated-field" readonly>
                        </div>
                        <div>
                            <label for="worked_hrs">WORKED HRS</label>
                            <input type="number" step="0.1" id="worked_hrs" name="worked_hrs" 
                                   class="calculated-field" readonly>
                        </div>
                        <div>
                            <label for="target">TARGET</label>
                            <input type="number" step="0.01" id="target" name="target">
                        </div>
                        <div>
                            <label for="actual">ACTUAL</label>
                            <input type="number" step="0.01" id="actual" name="actual">
                        </div>
                    </div>
                </div>

                <!-- Row 4: Performance Metrics (4 fields in one row) -->
                <div class="form-section">
                    <div class="row-4">
                        <div>
                            <label for="extra">EXTRA</label>
                            <input type="number" step="0.01" id="extra" name="extra" 
                                   class="calculated-field" readonly>
                        </div>
                        <div>
                            <label for="less">LESS</label>
                            <input type="number" step="0.01" id="less" name="less" 
                                   class="calculated-field" readonly>
                        </div>
                        <div>
                            <label for="incentive">INCENTIVE</label>
                            <input type="number" step="0.01" id="incentive" name="incentive" 
                                   class="calculated-field" readonly>
                        </div>
                        <div>
                            <label for="date_shift_id">DATE SHIFT ID</label>
                            <input type="text" id="date_shift_id" name="date_shift_id" 
                                   class="calculated-field" readonly>
                        </div>
                    </div>
                </div>

                <div class="button-container">
                    <button type="submit" class="btn-save">
                        <i class="fas fa-save"></i> Save Presence
                    </button>
                    <button type="reset" class="btn-clear">
                        <i class="fas fa-eraser"></i> Clear Form
                    </button>
                </div>
            </form>
        </div>
    </div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const dateInput = document.getElementById("date");
    const monthInput = document.getElementById("month");

    // Get current date
    const today = new Date();

    // Format for 'MONTH' field (e.g. Jun-25)
    const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                        "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
    const formattedMonth = `${monthNames[today.getMonth()]}-${String(today.getFullYear()).slice(2)}`;
    monthInput.value = formattedMonth;

    // Format for 'DATE' field as yyyy-mm-dd
    const day = String(today.getDate()).padStart(2, '0');
    const month = String(today.getMonth() + 1).padStart(2, '0');
    const year = today.getFullYear();
    dateInput.value = `${year}-${month}-${day}`;
});

$(document).ready(function() {
    // Initialize OP ID Select2 with enhanced search functionality
    $('#op_id').select2({
        placeholder: "-- Select or Search OP ID --",
        allowClear: true,
        width: '100%',
        dropdownAutoWidth: false,
        matcher: function(params, data) {
            // If there are no search terms, return all data
            if ($.trim(params.term) === '') {
                return data;
            }

            // Do not display the item if there is no 'text' property
            if (typeof data.text === 'undefined') {
                return null;
            }

            // `params.term` is the user's search term
            var searchTerm = params.term.toLowerCase();
            var optionText = data.text.toLowerCase();
            
            // Check if the search term matches OP ID or Name
            if (optionText.indexOf(searchTerm) > -1) {
                return data;
            }

            // Check if search term matches just the OP ID part or name part
            var parts = data.text.split(' - ');
            var opId = parts[0] ? parts[0].toLowerCase() : '';
            var opName = parts[1] ? parts[1].toLowerCase() : '';
            
            if (opId.indexOf(searchTerm) > -1 || opName.indexOf(searchTerm) > -1) {
                return data;
            }

            // Return `null` if the term should not be displayed
            return null;
        },
        templateResult: function(operator) {
            if (!operator.id) {
                return operator.text;
            }
            
            // For the dropdown display with inline styles for better visibility
            var parts = operator.text.split(' - ');
            var id = parts[0] || operator.id;
            var name = parts[1] || '';
            
            if (name) {
                var $result = $(
                    '<div style="padding: 4px 0; line-height: 1.3;">' +
                        '<div style="font-weight: bold; color: #333; font-size: 14px;">' + id + '</div>' +
                        '<div style="font-size: 11px; color: #000; font-style: italic; margin-top: 1px; font-weight: 500;">' + name + '</div>' +
                    '</div>'
                );
                return $result;
            } else {
                return $('<div style="font-weight: bold; color: #333; font-size: 14px;">' + id + '</div>');
            }
        },
        templateSelection: function(operator) {
            // For the selected display - show both ID and name
            if (!operator.id || operator.id === '') {
                return operator.text || operator.placeholder;
            }
            
            // Extract both OP ID and name for display
            var text = operator.text || '';
            var parts = text.split(' - ');
            var opId = parts[0] || operator.id;
            var opName = parts[1] || '';
            
            // Return both ID and name with proper styling
            if (opName) {
                return $(
                    '<span>' +
                        '<strong style="color: #333;">' + opId + '</strong>' +
                        '<span style="color: #000; margin-left: 5px;">- ' + opName + '</span>' +
                    '</span>'
                );
            } else {
                return $('<strong style="color: #333;">' + opId + '</strong>');
            }
        },
        language: {
            noResults: function() {
                return "No operators found. Try searching by ID or name.";
            },
            searching: function() {
                return "Searching operators...";
            }
        },
        escapeMarkup: function(markup) {
            return markup; // Allow HTML in results
        }
    });

    // Enhanced search with auto-focus
    $('#op_id').on('select2:opening', function() {
        // Focus on search box when dropdown opens
        setTimeout(function() {
            $('.select2-search__field').focus();
        }, 50);
    });

    // Handle OP ID selection change
    $('#op_id').on('select2:select', function(e) {
        var selectedData = e.params.data;
        console.log('Selected operator:', selectedData);
        console.log('Selected value:', $(this).val());
        
        // Update date shift ID when operator is selected
        updateDateShiftId();
    });

    // Handle OP ID clear
    $('#op_id').on('select2:clear', function() {
        console.log('Operator cleared');
        $('#date_shift_id').val('');
    });

    // Debug: Check if operators are loaded
    console.log('Available operators:', $('#op_id option').length);
    
    // Function to calculate time difference in hours
    function calculateHours(startTime, endTime) {
        if (!startTime || !endTime) return 0;
        
        const start = new Date(`2000-01-01 ${startTime}`);
        let end = new Date(`2000-01-01 ${endTime}`);
        
        // Handle overnight shift (like III shift: 22:00 to 06:00)
        if (end < start) {
            end.setDate(end.getDate() + 1);
        }
        
        const diffMs = end - start;
        return diffMs / (1000 * 60 * 60); // Convert to hours
    }

    // Function to calculate lunch break duration
    function calculateLunchBreak() {
        const lunchOut = $('#lunch_out').val();
        const lunchIn = $('#lunch_in').val();
        
        if (!lunchOut || !lunchIn) return 0;
        
        return calculateHours(lunchOut, lunchIn);
    }

    // Function to update all calculations
    function updateCalculations() {
        const startTime = $('#start_time').val();
        const endTime = $('#end_time').val();
        const target = parseFloat($('#target').val()) || 0;
        const actual = parseFloat($('#actual').val()) || 0;

        // Calculate total hours
        if (startTime && endTime) {
            const totalHrs = calculateHours(startTime, endTime);
            $('#total_hrs').val(totalHrs.toFixed(1));

            // Calculate worked hours (total - lunch break)
            const lunchBreak = calculateLunchBreak();
            const workedHrs = Math.max(0, totalHrs - lunchBreak);
            $('#worked_hrs').val(workedHrs.toFixed(1));
        }

        // Calculate extra/less
        const difference = actual - target;
        if (difference > 0) {
            $('#extra').val(difference.toFixed(2));
            $('#less').val('0.00');
        } else if (difference < 0) {
            $('#extra').val('0.00');
            $('#less').val(Math.abs(difference).toFixed(2));
        } else {
            $('#extra').val('0.00');
            $('#less').val('0.00');
        }

        // Calculate incentive (10% of extra production)
        const extra = parseFloat($('#extra').val()) || 0;
        const incentive = extra * 0.1;
        $('#incentive').val(incentive.toFixed(2));
    }

    // Function to update date shift ID
    function updateDateShiftId() {
        const date = $('#date').val();
        const shift = $('#shift').val();
        const opId = $('#op_id').val();

        console.log('UpdateDateShiftId - Date:', date, 'Shift:', shift, 'OpId:', opId);

        if (date && shift && opId) {
            // Format: YYYYMMDD_SHIFT_OPID
            const formattedDate = date.replace(/-/g, '');
            const dateShiftId = `${formattedDate}_${shift}_${opId}`;
            $('#date_shift_id').val(dateShiftId);
            console.log('Generated DateShiftId:', dateShiftId);
        } else {
            $('#date_shift_id').val('');
        }
    }

    // Event listeners for time calculations
    $('#start_time, #end_time, #lunch_out, #lunch_in').on('change', function() {
        console.log('Time changed:', $(this).attr('id'), $(this).val());
        updateCalculations();
    });
    
    // Event listeners for performance calculations
    $('#target, #actual').on('input change', function() {
        updateCalculations();
    });
    
    // Event listeners for date shift ID
    $('#date, #shift').on('change', function() {
        console.log('Date/Shift changed:', $(this).attr('id'), $(this).val());
        updateDateShiftId();
    });

    // Set default times based on shift selection
    $('#shift').on('change', function() {
        const shift = $(this).val();
        console.log('Shift selected:', shift);
        
        switch(shift) {
            case 'I':
                $('#start_time').val('06:00');
                $('#lunch_out').val('12:00');
                $('#lunch_in').val('12:30');
                $('#end_time').val('14:00');
                break;
            case 'II':
                $('#start_time').val('14:00');
                $('#lunch_out').val('18:00');
                $('#lunch_in').val('18:30');
                $('#end_time').val('22:00');
                break;
            case 'III':
                $('#start_time').val('22:00');
                $('#lunch_out').val('02:00');
                $('#lunch_in').val('02:30');
                $('#end_time').val('06:00');
                break;
            default:
                $('#start_time, #end_time, #lunch_out, #lunch_in').val('');
        }
        updateCalculations();
    });

    // Form reset handler
    $('button[type="reset"]').on('click', function(e) {
        e.preventDefault();
        
        // Reset form fields
        $('.presence-form')[0].reset();
        
        // Reset Select2
        $('#op_id').val(null).trigger('change');
        
        // Reinitialize date and month
        setTimeout(function() {
            const today = new Date();
            const monthNames = ["Jan", "Feb", "Mar", "Apr", "May", "Jun",
                               "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
            const formattedMonth = `${monthNames[today.getMonth()]}-${String(today.getFullYear()).slice(2)}`;
            $('#month').val(formattedMonth);
            
            const day = String(today.getDate()).padStart(2, '0');
            const month = String(today.getMonth() + 1).padStart(2, '0');
            const year = today.getFullYear();
            $('#date').val(`${year}-${month}-${day}`);
            
            // Clear all calculated fields
            $('.calculated-field').val('');
            
            console.log('Form reset completed');
        }, 100);
    });

    // Initial setup
    updateDateShiftId();
    
    // Debug: Log initial state
    console.log('Form initialized');
    console.log('Operators count:', <?= count($activeOperators) ?>);
});
</script>

<!-- Debug Information -->
<?php if (count($activeOperators) > 0): ?>
    <!-- Debug: Operators loaded successfully -->
    <script>
        console.log('PHP Debug: <?= count($activeOperators) ?> operators loaded');
        console.log('First operator: <?= htmlspecialchars($activeOperators[0]['op_id']) ?> - <?= htmlspecialchars($activeOperators[0]['name']) ?>');
    </script>
<?php else: ?>
    <script>
        console.error('PHP Debug: No operators found!');
    </script>
<?php endif; ?>

</body>
</html>