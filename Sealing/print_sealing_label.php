<?php
// filepath: c:\xampp\htdocs\Aabha\Sealing\print_sealing_label.php

// Get entry ID from URL parameter
$entryId = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($entryId <= 0) {
    die('Invalid entry ID');
}

// Include database connection
include '../Includes/db_connect.php';

try {
    // Fetch only sealing entry data
    $sql = "SELECT * FROM sealing_entry WHERE id = ?";
    $params = [$entryId];
    $stmt = sqlsrv_query($conn, $sql, $params);

    if (!$stmt) {
        throw new Exception('Query failed: ' . print_r(sqlsrv_errors(), true));
    }

    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
    if (!$row) {
        die('Entry not found');
    }
    sqlsrv_free_stmt($stmt);

} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}

sqlsrv_close($conn);

// Format date
$dateFormatted = '';
if (!empty($row['date'])) {
    if ($row['date'] instanceof DateTime) {
        $dateFormatted = $row['date']->format('Y-m-d');
    } else {
        $dateFormatted = date('Y-m-d', strtotime($row['date']));
    }
}$generatedTime = date('d/m/Y');
$currentTime = date('H:i:s');
// Only close if not already closed elsewhere
if (is_resource($conn)) {
    sqlsrv_close($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sealing Label - Entry #<?= $row['id'] ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            padding: 20px;
            background: #f5f5f5;
            color: #000;
        }
        
        .print-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .sealing-print-label {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
            padding: 15px;
            border: 2px solid #000;
            background: white;
            font-size: 10px;
            line-height: 1.3;
        }

        .label-header {
            text-align: center;
            font-weight: bold;
            margin-bottom: 8px;
            border-bottom: 1px dotted #000;
            padding-bottom: 5px;
            font-size: 11px;
        }

        .company-name {
            font-size: 12px;
            margin-bottom: 1px;
        }

        .department {
            font-size: 9px;
            margin-bottom: 3px;
        }

        .entry-id {
            margin-top: 5px;
            padding-top: 5px;
            border-top: 1px dotted #000;
            font-size: 10px;
            font-weight: bold;
        }

        .label-section {
            margin: 6px 0;
        }

        .section-title {
            text-align: center;
            font-weight: bold;
            text-decoration: underline;
            margin: 6px 0 4px 0;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .label-row {
            display: flex;
            justify-content: space-between;
            margin: 1px 0;
            padding: 0.5px 0;
        }

        .label-field {
            font-weight: normal;
            flex: 1;
            color: #000;
        }

        .label-value {
            text-align: right;
            font-weight: bold;
            max-width: 45%;
            word-wrap: break-word;
            color: #000;
        }

        .label-footer {
            text-align: center;
            font-size: 7px;
            color: #666;
            margin-top: 8px;
            border-top: 1px dotted #000;
            padding-top: 4px;
            line-height: 1.1;
        }

        .dotted-line {
            border-bottom: 1px dotted #000;
            margin: 4px 0;
        }

        .print-buttons {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
        }

        .btn {
            padding: 12px 24px;
            margin: 0 10px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-print {
            background: linear-gradient(135deg, #4caf50 0%, #45a049 100%);
            color: white;
            box-shadow: 0 2px 10px rgba(76, 175, 80, 0.3);
        }

        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(76, 175, 80, 0.4);
        }

        .btn-close {
            background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%);
            color: white;
            box-shadow: 0 2px 10px rgba(108, 117, 125, 0.3);
        }

        .btn-close:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(108, 117, 125, 0.4);
            color: white;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            
            .print-container {
                box-shadow: none;
                padding: 0;
                margin: 0;
                max-width: none;
            }
            
            .sealing-print-label {
                margin: 0;
                box-shadow: none;
                max-width: none;
                width: 100%;
            }
            
            .print-buttons {
                display: none;
            }
            
            @page {
                margin: 0.5in;
                size: auto;
            }
        }

        @media (max-width: 576px) {
            .print-container {
                padding: 10px;
                margin: 10px;
            }
            
            .sealing-print-label {
                padding: 12px;
                font-size: 9px;
            }
            
            .btn {
                padding: 10px 20px;
                font-size: 12px;
                margin: 5px;
                display: block;
                width: 100%;
                max-width: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <!-- Print Buttons (Hidden during print) -->
        <div class="print-buttons">
            <button type="button" class="btn btn-print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Label
            </button>
            <a href="sealing_lookup.php" class="btn btn-close">
                <i class="fas fa-times"></i> Close
            </a>
        </div>

        <!-- Sealing Label -->
        <div class="sealing-print-label">
            <div class="label-header">
                <div class="company-name">AABHA MFG.</div>
                <div class="department">Sealing Dept.</div>
                <div class="entry-id">ENTRY ID: <?= htmlspecialchars($row['id']) ?></div>
            </div>
            
            <!-- Basic Information Section -->
            <div class="label-section">
                <div class="label-row">
                    <span class="label-field">Date:</span>
                    <span class="label-value"><?= htmlspecialchars($dateFormatted) ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Shift:</span>
                    <span class="label-value"><?= htmlspecialchars($row['shift'] ?? '') ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Lot No.:</span>
                    <span class="label-value"><?= htmlspecialchars($row['lot_no'] ?? '') ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Bin No.:</span>
                    <span class="label-value"><?= htmlspecialchars($row['bin_no'] ?? '') ?></span>
                </div>
            </div>
            
            <div class="dotted-line"></div>
            
            <!-- Product Info Section -->
            <div class="section-title">PRODUCT INFO</div>
            <div class="label-section">
                <div class="label-row">
                    <span class="label-field">Type:</span>
                    <span class="label-value"><?= htmlspecialchars($row['flavour'] ?? '') ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Product:</span>
                    <span class="label-value">DESCRIPTION</span>
                </div>
            </div>
            
            <div class="dotted-line"></div>
            
            <!-- Machine & Time Section -->
            <div class="section-title">MACHINE & TIME</div>
            <div class="label-section">
                <div class="label-row">
                    <span class="label-field">M/C No.:</span>
                    <span class="label-value"><?= htmlspecialchars($row['machine_no'] ?? '') ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Start:</span>
                    <span class="label-value"><?= $currentTime ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Finish:</span>
                    <span class="label-value"><?= date('H:i:s', strtotime('+1 hour')) ?></span>
                </div>
            </div>
            
            <div class="dotted-line"></div>
            
            <!-- Production Section -->
            <div class="section-title">PRODUCTION</div>
            <div class="label-section">
                <div class="label-row">
                    <span class="label-field">Net Wt:</span>
                    <span class="label-value"><?= htmlspecialchars($row['seal_kg'] ?? '0.00') ?> kg</span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Avg Wt:</span>
                    <span class="label-value"><?= htmlspecialchars($row['avg_wt'] ?? '0.00') ?> g</span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Gross:</span>
                    <span class="label-value"><?= htmlspecialchars($row['seal_gross'] ?? '0.00') ?></span>
                </div>
                
                <div class="label-row">
                    <span class="label-field">Supervisor:</span>
                    <span class="label-value"><?= htmlspecialchars($row['supervisor'] ?? '') ?></span>
                </div>
            </div>
            
            <div class="dotted-line"></div>
            
            <!-- Footer -->
            <div class="label-footer">
                Generated: <?= htmlspecialchars($generatedTime) ?><br>
                Time: <?= $currentTime ?> pm<br>
                System Generated - No Signature Required
            </div>
        </div>
    </div>

    <!-- Auto-print script -->
    <script>
        // Auto-print when opened via print button (optional)
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('autoprint') === '1') {
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        }

        // Handle print completion
        window.onafterprint = function() {
            // Optional: Auto-close after printing
            if (urlParams.get('autoclose') === '1') {
                setTimeout(function() {
                    window.close();
                }, 1000);
            }
        };
    </script>
</body>
</html>