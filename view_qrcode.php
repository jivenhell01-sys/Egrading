<?php
require_once('DBConnection.php');

// Enable error reporting for debugging
error_reporting(0);
ini_set('display_errors', 0);

if (!isset($_GET['student_id'])) {
    die('<div class="alert alert-warning">No student ID provided.</div>');
}

$student_id = $_GET['student_id'];

try {
    // Get student details using prepared statement
    $stmt = $conn->prepare("SELECT * FROM student_list WHERE student_id = :student_id");
    $stmt->bindValue(':student_id', $student_id, SQLITE3_TEXT);
    $result = $stmt->execute();
    $student = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    // Get class details using prepared statement
    $class_name = 'N/A';
    if (!empty($student['class_id'])) {
        $stmt = $conn->prepare("SELECT * FROM class_list WHERE class_id = :class_id");
        $stmt->bindValue(':class_id', $student['class_id'], SQLITE3_TEXT);
        $result = $stmt->execute();
        $class = $result->fetchArray(SQLITE3_ASSOC);
        $class_name = $class ? htmlspecialchars($class['grade'] . ' - ' . $class['section']) : 'N/A';
    }
    
    // Generate a cache-busting parameter for the QR code image
    $cache_buster = '&_=' . time();
    ?>
  <div class="container-fluid py-4" style="background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); min-height: 50vh;">
    <div class="row justify-content-center align-items-center" style="min-height: 50vh;">
        <div class="col-md-8 col-lg-10">
            <div class="card border-0 shadow-sm rounded-3 overflow-hidden">
                <!-- Header -->
                <div class="card-header bg-primary bg-gradient text-white py-3">
                    <div class="d-flex align-items-center">
                        <i class="bi bi-person-badge fs-4 me-2"></i>
                        <div>
                            <h5 class="mb-0 fw-bold"><?php echo htmlspecialchars($student['name']) ?></h5>
                            <p class="mb-0 small opacity-75">Student ID: <?php echo htmlspecialchars($student['student_number']) ?></p>
                        </div>
                    </div>
                </div>
                
                <!-- Card Body -->
                <div class="card-body p-3">
                    <!-- QR Code -->
                    <div class="text-center my-3">
                        <div class="position-relative d-inline-block">
                            <img src="generate_qrcode.php?student_id=<?php echo urlencode($student_id) . $cache_buster ?>" 
                                 alt="QR Code" 
                                 class="img-fluid" 
                                 style="width: 220px; border: 6px solid white; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                            <span class="position-absolute top-0 start-50 translate-middle mt-n2 badge bg-primary px-3 py-1 rounded-pill shadow-sm small">
                                <i class="bi bi-qr-code-scan me-1"></i> Student ID
                            </span>
                        </div>
                    </div>
                    
                    <!-- Student Info -->
                    <div class="bg-light rounded-2 p-3 mb-3 small">
                        <div class="d-flex align-items-center mb-2">
                            <i class="bi bi-mortarboard text-primary me-2"></i>
                            <span class="text-muted">Class:</span>
                            <span class="ms-1 fw-medium"><?php echo $class_name ?></span>
                        </div>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-calendar3 text-primary me-2"></i>
                            <span class="text-muted">Generated:</span>
                            <span class="ms-1 fw-medium"><?php echo date('M d, Y') ?></span>
                        </div>
                    </div>
                    
                    <!-- Buttons -->
                    <div class="d-grid gap-2">
                        <button class="btn btn-primary btn-sm rounded-pill py-2" onclick="printQRCode()">
                            <i class="bi bi-printer-fill me-1"></i> Print QR Code
                        </button>
                        <button class="btn btn-outline-primary btn-sm rounded-pill py-2" onclick="saveQRCode()">
                            <i class="bi bi-download me-1"></i> Save QR Code
                        </button>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="card-footer bg-transparent border-0 text-center py-2">
                    <p class="text-muted small mb-0">
                        <i class="bi bi-shield-lock me-1"></i> 
                        Secured Student Identification
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

    <script>
    function printQRCode() {
        const printWindow = window.open('', '_blank');
        const studentName = <?php echo json_encode($student['name']); ?>;
        const studentNumber = <?php echo json_encode($student['student_number']); ?>;
        const className = <?php echo json_encode($class_name); ?>;
        const qrUrl = 'generate_qrcode.php?student_id=<?php echo urlencode($student_id) ?>';
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>QR Code - ${studentName}</title>
                <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
                <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
                <style>
                    @page { 
                        size: auto; 
                        margin: 10mm;
                    }
                    body { 
                        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
                        padding: 20px;
                        background-color: #f8f9fa;
                    }
                    .header { 
                        text-align: center; 
                        margin-bottom: 20px;
                        padding: 15px;
                        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
                        border-radius: 8px;
                    }
                    .header h2 { 
                        margin: 0;
                        color: #0d6efd;
                        font-weight: 600;
                    }
                    .header p { 
                        margin: 5px 0 0; 
                        color: #6c757d;
                        font-size: 0.9em;
                    }
                    .qr-container { 
                        display: flex; 
                        justify-content: center;
                        margin: 20px 0;
                    }
                    .qr-item { 
                        background: #e8e8e8ff;
                        border-radius: 10px;
                        padding: 15px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
                        transition: transform 0.2s, box-shadow 0.2s;
                        break-inside: avoid;
                        border: 5px solid darkgreen;
                        margin-bottom: 10px;
                        display: flex;
                        align-items: flex-start;
                        max-width: 400px;
                    }
                    .qr-item:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
                    }
                    .qr-item img { 
                        max-width: 120px; 
                        height: auto; 
                        margin-bottom: 5px;
                    }
                    .student-name { 
                        font-weight: bold; 
                        margin: 5px 0;
                        font-size: 14px;
                    }
                    .student-id { 
                        color: #666; 
                        font-size: 12px;
                        margin-bottom: 5px;
                    }
                    .no-print { display: none; }
                    @media print {
                        .no-print { display: none; }
                        .print-button { display: none; }
                        body { padding: 0; }
                    }
                </style>
            </head>
            <body onload="setTimeout(() => window.print(), 200);">
                <div class="header">
                    <h2>${className}</h2>
                    <p>Student QR Code</p>
                    <p>Generated on: ${new Date().toLocaleDateString()}</p>
                    <button class="print-button no-print" onclick="window.print()" style="margin: 10px 0; padding: 8px 15px; background: #0d6efd; color: white; border: none; border-radius: 4px; cursor: pointer;">
                        Print QR Code
                    </button>
                </div>
                <div class="qr-container">
                    <div class="qr-item d-flex align-items-start p-3">
                        <!-- QR Code Column -->
                        <div class="position-relative me-3" style="flex-shrink: 0; width: 130px;">
                            <img src="${qrUrl}" 
                                 alt="QR Code" 
                                 class="img-fluid"
                                 style="width: 100%; height: auto; aspect-ratio: 1/1; object-fit: cover; border: 4px solid white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                        </div>
                        
                        <!-- Text Content Column -->
                        <div class="text-start" style="flex: 1; min-width: 0; padding-left: 5px;">
                            <div class="institution-name small fw-bold mb-1" style="color: #0d6efd; font-size: 0.7rem; white-space: wrap; overflow: hidden; text-overflow: ellipsis;">EASTERN SAMAR STATE UNIVERSITY</div>
                            <div class="campus-name small text-uppercase mb-2" style="color: #198754; font-size: 0.6rem; letter-spacing: 0.5px;">Can-avid Campus</div>
                            <div class="student-name fw-bold mb-2" style="font-size: 0.9rem; white-space: wrap; overflow: hidden; text-overflow: ellipsis;">${studentName}</div>
                            <div class="d-flex align-items-center mb-2">
                                <span class="badge bg-primary shadow-sm me-2" style="font-size: 0.6rem;">
                                    <i class="bi bi-person-badge me-1"></i>${studentNumber}
                                </span>
                            </div>
                            <div class="student-section small text-muted" style="font-weight:bold;">${className}</div>
                        </div>
                    </div>
                </div>
                <script>
                    // Close the print window after printing
                    window.onafterprint = function() {
                        setTimeout(() => window.close(), 100);
                    };
                <\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    function saveQRCode() {
        const link = document.createElement('a');
        const studentName = <?php echo json_encode(preg_replace('/[^a-z0-9]+/i', '_', $student['name'])); ?>;
        const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
        
        link.href = 'generate_qrcode.php?student_id=<?php echo urlencode($student_id) ?>';
        link.download = `QR_${studentName}_${timestamp}.png`;
        link.style.display = 'none';
        
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Show success message
        const toast = document.createElement('div');
        toast.className = 'position-fixed bottom-0 end-0 m-3 p-3 bg-success text-white rounded shadow';
        toast.style.zIndex = '9999';
        toast.innerHTML = '<i class="bi bi-check-circle me-2"></i> QR Code downloaded successfully';
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.transition = 'opacity 0.5s';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 500);
        }, 2000);
    }
    </script>
    <?php
} catch (Exception $e) {
    echo '<div class="container mt-4">
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Error: ' . htmlspecialchars($e->getMessage()) . '
            </div>
          </div>';
}
?>
