<?php
$customSessionPath = dirname(__DIR__) . '/temp';
if (!is_dir($customSessionPath)) {
    @mkdir($customSessionPath, 0777, true);
}
if (is_writable($customSessionPath)) {
    ini_set('session.save_path', $customSessionPath);
}
session_start();
if (!isset($_SESSION['operator_id'])) {
    header("Location: ../index.php");
    exit;
}
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

include '../Includes/db_connect.php';

// Verify database connection
if ($conn === false) {
    die("Database connection failed: " . print_r(sqlsrv_errors(), true));
}

// Get Dipping department's dept_id with error handling
$successMsg = '';  // Initialize the variable
$dippingDeptId = null;
$deptSql = "SELECT dept_id FROM departments WHERE department_name = 'Dipping'";
$deptStmt = sqlsrv_query($conn, $deptSql);

if ($deptStmt === false) {
    die("SQL Error (dept query): " . print_r(sqlsrv_errors(), true));
}

if ($deptStmt && ($deptRow = sqlsrv_fetch_array($deptStmt, SQLSRV_FETCH_ASSOC))) {
    $dippingDeptId = $deptRow['dept_id'];
}
sqlsrv_free_stmt($deptStmt);

// Handle AJAX request for lot total weight
if (isset($_POST['action']) && $_POST['action'] === 'fetch_lot_total_weight') {
    header('Content-Type: application/json');
    
    $lot_no = $_POST['lot_no'] ?? '';
    $total_weight = 0;

    if ($lot_no) {
        $sql = "SELECT SUM(wt_kg) as total_weight FROM dipping_binwise_entry WHERE lot_no = ?";
        $stmt = sqlsrv_prepare($conn, $sql, array(&$lot_no));
        
        if ($stmt === false) {
            echo json_encode(['success' => false, 'error' => 'Failed to prepare query']);
            exit;
        }
        
        if (!sqlsrv_execute($stmt)) {
            echo json_encode(['success' => false, 'error' => 'Failed to execute query']);
            exit;
        }
        
        if ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $total_weight = $row['total_weight'] ? number_format($row['total_weight'], 2) : '0.00';
        }
        sqlsrv_free_stmt($stmt);
    }

    echo json_encode(['success' => true, 'total_weight' => $total_weight]);
    exit;
}

// Fetch supervisor list with error handling
$supervisorList = [];
if ($dippingDeptId !== null) {
    // First try to get supervisors for Dipping department
    $sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND department_id = ? AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
    $stmt = sqlsrv_query($conn, $sql, [$dippingDeptId]);
    
    if ($stmt === false) {
        die("SQL Error (supervisor query): " . print_r(sqlsrv_errors(), true));
    }
    
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $supervisorList[] = $row['grn_checked_by'];
    }
    sqlsrv_free_stmt($stmt);
    
    // If no supervisors found for Dipping department, get all supervisors as fallback
    if (empty($supervisorList)) {
        $sql = "SELECT DISTINCT grn_checked_by FROM check_by WHERE menu = 'Supervisor' AND grn_checked_by IS NOT NULL AND grn_checked_by != ''";
        $stmt = sqlsrv_query($conn, $sql);
        
        if ($stmt !== false) {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                $supervisorList[] = $row['grn_checked_by'];
            }
            sqlsrv_free_stmt($stmt);
        }
    }
}

// Fetch lot dropdown data with error handling
$lots = [];
$sql = "SELECT id, lot_no, product_type, machine_no, product_description FROM lots WHERE is_deleted = 0";
$stmt = sqlsrv_query($conn, $sql);

if ($stmt === false) {
    die("SQL Error (lots query): " . print_r(sqlsrv_errors(), true));
}

while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
    $lots[] = $row;
}
sqlsrv_free_stmt($stmt);

// Edit mode with error handling
$editData = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $editId = intval($_GET['id']);
    $sql = "SELECT * FROM dipping_binwise_entry WHERE id = ?";
    $stmt = sqlsrv_prepare($conn, $sql, array(&$editId));
    
    if ($stmt === false) {
        die("SQL Error (edit prepare): " . print_r(sqlsrv_errors(), true));
    }
    
    if (!sqlsrv_execute($stmt)) {
        die("SQL Error (edit execute): " . print_r(sqlsrv_errors(), true));
    }
    
    $editData = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($stmt);
}

// Handle form submission for both insert and update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Prepare common parameters with null coalescing to prevent undefined key warnings
    $binNo = $_POST['binNo'] ?? '';
    $entryDate = $_POST['entryDate'] ?? '';
    $shift = $_POST['shift'] ?? '';
    $lotNo = $_POST['lotNo'] ?? '';
    $binStartTime = $_POST['binStartTime'] ?? '';
    $binFinishTime = $_POST['binFinishTime'] ?? '';
    $wtKg = $_POST['wtKg'] ?? 0;
    $avgWt = $_POST['avgWt'] ?? 0;
    $gross = $_POST['gross'] ?? 0;
    $supervisor = $_POST['supervisor'] ?? '';
    $productType = $_POST['productType'] ?? '';
    $machineNo = $_POST['machineNo'] ?? '';
    $product = $_POST['product'] ?? '';

    if (isset($_POST['edit_id'])) {
        // Update logic
        $editId = $_POST['edit_id'];
        $sql = "UPDATE dipping_binwise_entry SET 
                    bin_no = ?, entry_date = ?, shift = ?, lot_no = ?, 
                    bin_start_time = ?, bin_finish_time = ?, wt_kg = ?, 
                    avg_wt = ?, gross = ?, supervisor = ?, 
                    product_type = ?, machine_no = ?, product = ? 
                WHERE id = ?";
        $params = [$binNo, $entryDate, $shift, $lotNo, $binStartTime, $binFinishTime, $wtKg, $avgWt, $gross, $supervisor, $productType, $machineNo, $product, $editId];
    } else {
        // Insert logic
        $sql = "INSERT INTO dipping_binwise_entry 
                    (bin_no, entry_date, shift, lot_no, 
                    bin_start_time, bin_finish_time, wt_kg, 
                    avg_wt, gross, supervisor, 
                    product_type, machine_no, product)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [$binNo, $entryDate, $shift, $lotNo, $binStartTime, $binFinishTime, $wtKg, $avgWt, $gross, $supervisor, $productType, $machineNo, $product];
    }

    $stmt = sqlsrv_prepare($conn, $sql, $params);
    if ($stmt === false) {
        die('SQL Error (insert/update): ' . print_r(sqlsrv_errors(), true));
    }

    if (sqlsrv_execute($stmt)) {
        // Redirect to lookup page after successful insert/update
        header("Location: DippingBinwiseEntryLookup.php");
        exit;
    } else {
        $errorMsg = "Error in " . (isset($_POST['edit_id']) ? "updating" : "adding") . " entry: " . print_r(sqlsrv_errors(), true);
    }
    sqlsrv_free_stmt($stmt);
}

// Date and Time handling for edit mode
$entryDate = isset($editData['entry_date']) && $editData['entry_date'] instanceof DateTime 
    ? $editData['entry_date']->format('Y-m-d') : date('Y-m-d');

$startTime = isset($editData['bin_start_time']) && $editData['bin_start_time'] instanceof DateTime 
    ? $editData['bin_start_time']->format('H:i') : '';

$finishTime = isset($editData['bin_finish_time']) && $editData['bin_finish_time'] instanceof DateTime 
    ? $editData['bin_finish_time']->format('H:i') : '';

include '../Includes/sidebar.php';
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />

  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Dipping Entry Page</title>
<link rel="stylesheet" href="../asset/style.css" />
  <!-- Add this in your <head> section if not already present -->
  <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

</head>

<body>
  <div class="wrapper">
    <div class="container">
      <h1 style="background-color: #007bff; color: white; padding: 5px; border-radius: 5px;">
        DIPPING BINWISE ENTRY FORM
      </h1>
      <?php if ($successMsg): ?>
        <script>
          alert("<?= $successMsg ?>");
          window.location.href = window.location.pathname; // reload to clear form
        </script>
      <?php endif; ?>
      
      <?php if (isset($errorMsg) && $errorMsg): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
          <i class="fas fa-exclamation-triangle me-2"></i>
          <?= htmlspecialchars($errorMsg) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
      <?php endif; ?>
      <form id="dippingBinForm" method="POST">
        <?php if ($editData): ?>
          <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
        <?php endif; ?>
        <div class="form-grid">

          <div>
            <label for="binNo">BIN.NO.</label>
            <input type="number" id="binNo" name="binNo" readonly
              value="<?= htmlspecialchars($editData['bin_no'] ?? '') ?>"
              style="background-color: #f8f9fa;">
          </div>
          <div>
            <label for="entryDate">DATE</label>
            <input type="date" id="entryDate" name="entryDate" value="<?= $entryDate ?>" required 
                   title="Click to open calendar picker. Use ↑/↓ arrow keys or +/- to change date quickly. Press Home for today's date.">
          </div>
          <div>
            <label for="shift">SHIFT</label>
            <div class="shift-group">
                <select id="shift" name="shift" required>
                  <option value="">Select Shift</option>
                  <option value="I" <?= (isset($editData['shift']) && $editData['shift'] == 'I') ? 'selected' : '' ?>>I</option>
                  <option value="II" <?= (isset($editData['shift']) && $editData['shift'] == 'II') ? 'selected' : '' ?>>II</option>
                  <option value="III" <?= (isset($editData['shift']) && $editData['shift'] == 'III') ? 'selected' : '' ?>>III</option>
                </select>
                <button type="button" onclick="resetShift()" title="Reset Shift">
                  <i class="fas fa-sync-alt"></i>
                </button>
            </div>
          </div>

          <div>
            <label for="lotNo">LOT NO.</label>
            <select id="lotNo" name="lotNo" class="form-control lotno-select2" required style="width:100%;">
                <option value="">Select Lot No.</option>
                <?php foreach ($lots as $lot): ?>
                    <option value="<?= htmlspecialchars($lot['lot_no']) ?>"
                        data-product_type="<?= htmlspecialchars($lot['product_type']) ?>"
                        data-machine_no="<?= htmlspecialchars($lot['machine_no']) ?>"
                        data-product_description="<?= htmlspecialchars($lot['product_description']) ?>"
                        <?= (isset($editData['lot_no']) && $editData['lot_no'] == $lot['lot_no']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lot['lot_no']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <!-- Add this display field for total weight -->
            <div id="lotTotalWeight" class="alert alert-info mt-2" style="display:none; margin-top: 8px; padding: 8px; font-size: 0.9em; border-radius: 4px; background-color: #e3f2fd; border: 1px solid #2196f3;">
                <strong><i class="fas fa-weight"></i> Total Weight for this Lot:</strong> <span id="lotTotalWeightValue">0.00</span> kg
            </div>
          </div>
          <div>
            <label for="binStartTime">BinStartTime</label>
            <input type="time" id="binStartTime" name="binStartTime" required value="<?= $startTime ?>">
          </div>
          <div>
            <label for="binFinishTime">BinFinishTime</label>
            <input type="time" id="binFinishTime" name="binFinishTime" required value="<?= $finishTime ?>">
          </div>
          <div>
            <label for="wtKg">WT. IN KG</label>
            <input type="number" step="0.01" id="wtKg" name="wtKg" required
              value="<?= htmlspecialchars($editData['wt_kg'] ?? '') ?>">
          </div>
          <div>
            <label for="avgWt">AVG. WT.</label>
            <input type="number" step="0.01" id="avgWt" name="avgWt" required
              value="<?= htmlspecialchars($editData['avg_wt'] ?? '') ?>">
          </div>
          <div>
            <label for="gross">GROSS</label>
            <input type="number" step="0.01" id="gross" name="gross" readonly
              value="<?= htmlspecialchars($editData['gross'] ?? '') ?>">
          </div>
          <div>
            <label for="supervisor">SUPERVISOR</label>
            <select id="supervisor" name="supervisor" required>
              <option value="">Select Supervisor</option>
              <?php foreach ($supervisorList as $sup): ?>
                <option value="<?= htmlspecialchars($sup) ?>" <?= (isset($editData['supervisor']) && $editData['supervisor'] == $sup) ? 'selected' : '' ?>><?= htmlspecialchars($sup) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="productType">Product Type</label>
            <input type="text" id="productType" name="productType" readonly
              value="<?= htmlspecialchars($editData['product_type'] ?? '') ?>">
          </div>
          <div>
            <label for="machineNo">Machine No.</label>
            <input type="text" id="machineNo" name="machineNo" readonly
              value="<?= htmlspecialchars($editData['machine_no'] ?? '') ?>">
          </div>
          <div>
            <label for="product">Product</label>
            <input type="text" id="product" name="product" readonly
              value="<?= htmlspecialchars($editData['product'] ?? '') ?>">
          </div>
          <div
            style="text-align: center; margin-top: 20px; display: flex; flex-direction: row; gap: 12px; justify-content: center; width: 100%;">
            <button type="submit"
              style="background-color: #4CAF50; padding: 10px 20px; border: none; color: white; border-radius: 5px; cursor: pointer;">
              Submit
            </button>
            <a href="DippingBinwiseEntryLookup.php"
              style="background-color: #2c80b4; padding: 10px 20px; color: white; border-radius: 5px; cursor: pointer; text-decoration: none; text-align: center;">
              Cancel
            </a>
          </div>


        </div>

      </form>
    </div>
  </div>

  <script>
// Add time validation
document.getElementById('binStartTime').addEventListener('change', function() {
    validateTimeFields();
    setMinFinishTime();
});

document.getElementById('binFinishTime').addEventListener('change', function() {
    validateTimeFields();
});

function setMinFinishTime() {
    const startTime = document.getElementById('binStartTime').value;
    const finishTimeInput = document.getElementById('binFinishTime');
    
    if (startTime) {
        // Remove the restrictive min attribute to allow cross-midnight times
        // The validation will be handled by validateTimeFields() function
        finishTimeInput.removeAttribute('min');
    } else {
        finishTimeInput.removeAttribute('min');
    }
}

function validateTimeFields() {
    const startTime = document.getElementById('binStartTime').value;
    const finishTime = document.getElementById('binFinishTime').value;
    
    if (startTime && finishTime) {
        // Convert time strings to Date objects for proper comparison
        const today = new Date();
        const startDateTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 
                                      parseInt(startTime.split(':')[0]), parseInt(startTime.split(':')[1]));
        let finishDateTime = new Date(today.getFullYear(), today.getMonth(), today.getDate(), 
                                     parseInt(finishTime.split(':')[0]), parseInt(finishTime.split(':')[1]));
        
        // If finish time is earlier in the day than start time, assume it's the next day
        if (finishDateTime <= startDateTime) {
            finishDateTime.setDate(finishDateTime.getDate() + 1);
        }
        
        // Now check if the duration is reasonable (less than 24 hours)
        const timeDiffMs = finishDateTime - startDateTime;
        const timeDiffHours = timeDiffMs / (1000 * 60 * 60);
        
        if (timeDiffHours <= 0 || timeDiffHours >= 24) {
            alert('Bin Finish Time must be greater than Bin Start Time and within 24 hours');
            document.getElementById('binFinishTime').value = '';
            document.getElementById('binFinishTime').focus();
            return false;
        }
    }
    return true;
}

// Add form submission validation
document.getElementById('dippingBinForm').addEventListener('submit', function(e) {
    // Enable disabled fields before form submission so they get included in POST data
    const shiftSelect = document.getElementById('shift');
    const supervisorSelect = document.getElementById('supervisor');
    
    if (shiftSelect && shiftSelect.disabled) {
        shiftSelect.disabled = false;
    }
    if (supervisorSelect && supervisorSelect.disabled) {
        supervisorSelect.disabled = false;
    }
    
    if (!validateTimeFields()) {
        e.preventDefault();
        return false;
    }
});

// Set entryDate and handle bin auto-increment from database
document.addEventListener("DOMContentLoaded", function () {
    <?php if (!$editData): ?>
      const entryDate = document.getElementById("entryDate");
      if (entryDate) {
        const today = new Date();
        entryDate.value = today.toISOString().split('T')[0];
      }
      
      // Get initial bin number on page load
      getNextBinNumber();
    <?php endif; ?>

    // Set initial min time for finish time if start time is already set
    setMinFinishTime();
});

// Function to get next bin number from database (for initial load)
function getNextBinNumber() {
    <?php if (!$editData): ?>
      fetch('get_next_bin_no.php')
        .then(response => response.json())
        .then(data => {
          document.getElementById('binNo').value = data.next_bin_no;
        })
        .catch(error => {
          console.error('Error fetching next bin number:', error);
          document.getElementById('binNo').value = 1;
        });
    <?php endif; ?>
}

// Function to get next bin number for specific lot
function getNextBinNumberForLot(lotNo) {
    if (!lotNo) {
        // If no lot selected, clear bin number
        document.getElementById('binNo').value = '';
        return;
    }
    fetch('get_next_bin_no.php?lot_no=' + encodeURIComponent(lotNo))
        .then(response => response.json())
        .then(data => {
            document.getElementById('binNo').value = data.next_bin_no;
        })
        .catch(error => {
            console.error('Error fetching next bin number:', error);
            document.getElementById('binNo').value = 1;
        });
}

// Update lotNo change event to fetch bin number per lot
document.getElementById('lotNo').addEventListener('change', function () {
    var lotNo = this.value;
    getNextBinNumberForLot(lotNo);

    // Existing auto-fill code
    var selected = this.options[this.selectedIndex];
    document.getElementById('productType').value = selected.getAttribute('data-product_type') || '';
    document.getElementById('machineNo').value = selected.getAttribute('data-machine_no') || '';
    document.getElementById('product').value = selected.getAttribute('data-product_description') || '';
    if (window.jQuery) {
        fetchLotTotalWeight(lotNo);
    }
});

// Also handle Select2 change event for lot dropdown
$(document).ready(function() {
    // Initialize Select2 for LOT NO. with search
    $('.lotno-select2').select2({
        placeholder: "Search and select Lot No.",
        allowClear: true,
        width: '100%',
        dropdownAutoWidth: true
    });

    // Handle Select2 change event for lot number
    $('#lotNo').on('select2:select', function (e) {
        var lotNo = e.params.data.id;
        getNextBinNumberForLot(lotNo);
        
        var selected = this.options[this.selectedIndex];
        $('#productType').val(selected.getAttribute('data-product_type') || '');
        $('#machineNo').val(selected.getAttribute('data-machine_no') || '');
        $('#product').val(selected.getAttribute('data-product_description') || '');
        
        fetchLotTotalWeight(lotNo);
    });

    // Handle Select2 clear event
    $('#lotNo').on('select2:clear', function (e) {
        document.getElementById('binNo').value = '';
        $('#productType').val('');
        $('#machineNo').val('');
        $('#product').val('');
        $('#lotTotalWeight').hide();
    });

    // Auto-fill product fields when lotNo changes (fallback for regular change event)
    $('#lotNo').on('change', function () {
        var selected = this.options[this.selectedIndex];
        var lotNo = $(this).val();
        
        // Auto-fill product fields
        $('#productType').val(selected.getAttribute('data-product_type') || '');
        $('#machineNo').val(selected.getAttribute('data-machine_no') || '');
        $('#product').val(selected.getAttribute('data-product_description') || '');
        
        // Fetch and display total weight for selected lot
        fetchLotTotalWeight(lotNo);
    });
});

// Calculate gross
document.getElementById('wtKg').addEventListener('input', calculateGross);
document.getElementById('avgWt').addEventListener('input', calculateGross);

function calculateGross() {
    const wtKg = parseFloat(document.getElementById('wtKg').value) || 0;
    const avgWt = parseFloat(document.getElementById('avgWt').value) || 0;
    let gross = '';
    if (wtKg > 0 && avgWt > 0) {
      gross = (wtKg * 1000 / avgWt / 144).toFixed(2);
    }
    document.getElementById('gross').value = gross;
}

// Shift Locking Script (only for add mode)
<?php if (!$editData): ?>
  const shiftSelect = document.getElementById("shift");
  const supervisorSelect = document.getElementById("supervisor");

  window.addEventListener("DOMContentLoaded", function () {
    const savedShift = localStorage.getItem("selectedShift");
    const savedSupervisor = localStorage.getItem("selectedSupervisor");
    if (savedShift) {
      shiftSelect.value = savedShift;
      shiftSelect.setAttribute("data-locked", "true");
      shiftSelect.classList.add("locked-shift");
      shiftSelect.disabled = true;
    }
    if (savedSupervisor) {
      supervisorSelect.value = savedSupervisor;
      supervisorSelect.setAttribute("data-locked", "true");
      supervisorSelect.classList.add("locked-supervisor");
      supervisorSelect.disabled = true;
    }
  });

  shiftSelect.addEventListener("change", function () {
    if (shiftSelect.value !== "") {
      localStorage.setItem("selectedShift", shiftSelect.value);
      shiftSelect.setAttribute("data-locked", "true");
      shiftSelect.classList.add("locked-shift");
      shiftSelect.disabled = true;
    }
  });

  supervisorSelect.addEventListener("change", function () {
    if (supervisorSelect.value !== "") {
      localStorage.setItem("selectedSupervisor", supervisorSelect.value);
      supervisorSelect.setAttribute("data-locked", "true");
      supervisorSelect.classList.add("locked-supervisor");
      supervisorSelect.disabled = true;
    }
  });

  function resetShift() {
    localStorage.removeItem("selectedShift");
    localStorage.removeItem("selectedSupervisor");
    shiftSelect.removeAttribute("data-locked");
    shiftSelect.classList.remove("locked-shift");
    shiftSelect.value = "";
    shiftSelect.disabled = false;

    supervisorSelect.removeAttribute("data-locked");
    supervisorSelect.classList.remove("locked-supervisor");
    supervisorSelect.value = "";
    supervisorSelect.disabled = false;
  }
<?php endif; ?>

// Add this function to your existing script section
function fetchLotTotalWeight(lotNo) {
    if (!lotNo) {
        $('#lotTotalWeight').hide();
        return;
    }
    
    $.ajax({
        url: '',
        method: 'POST',
        data: {
            action: 'fetch_lot_total_weight',
            lot_no: lotNo
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                $('#lotTotalWeightValue').text(response.total_weight);
                $('#lotTotalWeight').show();
            } else {
                $('#lotTotalWeightValue').text('0.00');
                $('#lotTotalWeight').show();
            }
        },
        error: function() {
            $('#lotTotalWeightValue').text('0.00');
            $('#lotTotalWeight').show();
        }
    });
}

// Update your existing lot change event handler
$(document).ready(function() {
    // Initialize Select2 for LOT NO.
    $('.lotno-select2').select2({
        placeholder: "Select Lot No.",
        allowClear: true,
        width: '100%'
    });

    // Enhanced date picker functionality
    const entryDateInput = document.getElementById('entryDate');
    if (entryDateInput) {
        // Add calendar icon click handler for better UX
        entryDateInput.addEventListener('focus', function() {
            this.showPicker && this.showPicker(); // Modern browsers
        });
        
        // Add keyboard shortcuts for date navigation
        entryDateInput.addEventListener('keydown', function(e) {
            const currentDate = new Date(this.value || new Date());
            let newDate = new Date(currentDate);
            
            if (e.key === 'ArrowUp' || e.key === '+') {
                e.preventDefault();
                newDate.setDate(currentDate.getDate() + 1);
                this.value = newDate.toISOString().split('T')[0];
            } else if (e.key === 'ArrowDown' || e.key === '-') {
                e.preventDefault();
                newDate.setDate(currentDate.getDate() - 1);
                this.value = newDate.toISOString().split('T')[0];
            } else if (e.key === 'Home') {
                e.preventDefault();
                this.value = new Date().toISOString().split('T')[0]; // Today
            }
        });
        
        // Add visual feedback when date changes
        entryDateInput.addEventListener('change', function() {
            this.style.backgroundColor = '#e3f2fd';
            setTimeout(() => {
                this.style.backgroundColor = '';
            }, 300);
        });
    }

    // Auto-fill product fields when lotNo changes
    $('#lotNo').on('change', function () {
        var selected = this.options[this.selectedIndex];
        var lotNo = $(this).val();
        
        // Auto-fill product fields
        $('#productType').val(selected.getAttribute('data-product_type') || '');
        $('#machineNo').val(selected.getAttribute('data-machine_no') || '');
        $('#product').val(selected.getAttribute('data-product_description') || '');
        
        // Fetch and display total weight for selected lot
        fetchLotTotalWeight(lotNo);
    });

    // Ensure auto-fill works on page load (edit mode or pre-selected lot)
    var lotNoSelect = document.getElementById('lotNo');
    if (lotNoSelect && lotNoSelect.value) {
        $(lotNoSelect).trigger('change');
    }
});
  </script>

  <style>
    /* Force 24-hour format for time input (works in some browsers) */
input[type="time"]::-webkit-datetime-edit-ampm-field {
    display: none;
}

/* Style date input to make it more prominent */
input[type="date"] {
    background: white;
    border: 2px solid #ddd;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 16px;
    transition: all 0.3s ease;
    cursor: pointer;
}

input[type="date"]:hover {
    border-color: #007bff;
    box-shadow: 0 2px 8px rgba(0, 123, 255, 0.1);
}

input[type="date"]:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    outline: none;
}

/* Style the calendar icon */
input[type="date"]::-webkit-calendar-picker-indicator {
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: background-color 0.3s ease;
}

input[type="date"]::-webkit-calendar-picker-indicator:hover {
    background-color: rgba(0, 123, 255, 0.1);
}
  </style>

</body>

</html>

<?php
