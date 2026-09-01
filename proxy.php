<?php
/**
 * proxy.php
 * -----------------------------------------------------------------------
 * รับ request จากหน้า index.html (สแกนชิ้นงาน) แล้วทำ 2 อย่างตาม "type" ของ request:
 *
 * 1) type = "barcode_training"
 *    -> เก็บรูป QR/บาร์โค้ดไว้เป็นชุดข้อมูลฝึกโมเดล (ไม่ส่งไป Roboflow วิเคราะห์)
 *
 * 2) ไม่มี type (request ปกติ) -> คาดว่ามี field "images": { front, back, barcode }
 *    -> ส่งรูปด้านหน้า, ด้านหลัง, และบาร์โค้ด/QR (ถ้ามีส่งมา) ไปวิเคราะห์กับ Roboflow
 *       ทั้งหมด (เรียกทีละรูป เพราะ Roboflow Workflow API มาตรฐานรับรูปครั้งละ 1 รูปต่อ 1
 *       request) — ทั้ง 3 ฝั่งใช้ Roboflow Workflow เดียวกัน (เทรนร่วมโมเดลเดียวกัน) และแสดง
 *       กรอบ+% ความมั่นใจแบบเดียวกันทุกประการ (คลาสตรงรหัสพาร์ท และมั่นใจ >= 80%
 *       (CONFIDENCE_THRESHOLD) ถึงจะขึ้นกรอบเขียว/OK) — ไม่มีการอ่านตัวอักษรจากตัวบาร์โค้ด/QR
 *       !! ผลของบาร์โค้ดจะ "ไม่" ถูกนำมารวมคิดสถานะ OK/NG/total_detections ของชิ้นงาน
 *       เด็ดขาด (นับเฉพาะผลของ front + back เท่านั้น) — เป็นข้อมูลอ้างอิงเสริมที่แสดงในหน้าเว็บ
 *       เท่านั้น
 *    -> รวมผลเป็น { outputs: [ ผลด้านหน้า, ผลด้านหลัง, ผลบาร์โค้ด ] } ให้ตรงกับที่ index.html
 *       คาดหวัง (outputs[0] = หน้า, outputs[1] = หลัง, outputs[2] = บาร์โค้ด ถ้ามีส่งมา)
 *    -> บันทึกผลลงไฟล์ data/inspections.json เพื่อให้หน้า "ข้อมูลชิ้นงาน", "Material"
 *       และ "สรุปผลทั้งหมด" อ่านไปแสดงได้
 *
 * ============================ ต้องแก้ก่อนใช้งานจริง ============================
 * กรอกค่า config ด้านล่างให้ตรงกับ Roboflow ของคุณ (API key และ URL ของ Workflow ที่ใช้อยู่เดิม)
 *
 * บางรหัสพาร์ทใช้ Roboflow Workflow คนละตัวกับตัวหลัก (ดูตัวแปร $PART_WORKFLOW_OVERRIDES
 * ด้านล่าง) ถ้ารหัสพาร์ทไม่มีอยู่ในรายการนี้ ระบบจะใช้ Workflow เริ่มต้น (ROBOFLOW_WORKFLOW_URL)
 * =================================================================================
 */

// ---------------------------------------------------------------------------
// CONFIG: แก้ 2 บรรทัดนี้ให้ตรงกับของจริง
// ---------------------------------------------------------------------------
define('ROBOFLOW_API_KEY', 'iHYsaB8L6oVZdmrtFw7T');

// Workflow เริ่มต้น (ใช้กับพาร์ทที่ไม่มีรายการเฉพาะด้านล่าง)
define('ROBOFLOW_WORKFLOW_URL', 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-2-4');

// รายชื่อรหัสพาร์ททั้งหมด (พร้อม Workflow URL เฉพาะของแต่ละพาร์ท ถ้ามี) ย้ายจากที่เคย
// hardcode ไว้ตรงนี้ ไปเก็บเป็นไฟล์ data/parts.json แทน (ดูฟังก์ชัน loadPartsList() และ
// getDefaultPartsSeed() ด้านล่าง) เพื่อให้จัดการผ่านหน้า "Part Master" ใน index.html ได้
// โดยไม่ต้องแก้โค้ด/deploy ใหม่ทุกครั้งที่เพิ่ม-ลบ-แก้ไขพาร์ท ไฟล์ data/parts.json จะถูก
// สร้างขึ้นอัตโนมัติพร้อม seed ข้อมูลชุดเดิม (ที่เคย hardcode ไว้) ในการเรียกใช้งานครั้งแรก

// รหัสพาร์ทที่ Workflow ต้องการรับรูปเป็น "url" (type: "url") แทนที่จะเป็น base64 ตรงๆ
// ตามตัวอย่าง curl ที่ได้รับมา:
//   { "api_key": "...", "inputs": { "image": {"type": "url", "value": "IMAGE_URL"} } }
//
// เคยทดลองเปลี่ยน JB3Z17A870B ให้ใช้ url input แล้ว แต่ Roboflow ดึงรูปจาก hosting นี้
// (ai-based.freehosting.dev) ไม่ได้จริง เจอ error "Remote end closed connection without
// response" (HTTP 400 จาก Roboflow) เป็นข้อจำกัดของ free hosting ที่บล็อก/ตัดการเชื่อมต่อ
// จากบอท/เซิร์ฟเวอร์ภายนอกที่ไม่ใช่เบราว์เซอร์จริง จึงย้อนกลับมาใช้ base64 เหมือนเดิมทั้งคู่
// (JB3Z17A869B และ JB3Z17A870B) — ปล่อย array นี้ว่างไว้ตามเดิม
//
// หมายเหตุ: URL ที่ส่งให้ Roboflow ต้องเป็น URL ที่ Roboflow เข้าถึงจากอินเทอร์เน็ตได้จริง
// (hosting ต้องไม่บล็อกการเข้าถึงจากเซิร์ฟเวอร์ภายนอก) ถ้าย้าย hosting ไปที่อื่นที่รองรับ
// แล้วอยากลองใหม่ ใส่รหัสพาร์ทกลับเข้า array นี้ได้ เช่น ['JB3Z17A870B']
$PARTS_USE_URL_INPUT = []; // JB3Z17A869B และ JB3Z17A870B ใช้ Base64 เพราะ Roboflow ดึงรูปจาก FreeHosting URL ไม่ได้

/**
 * หา Workflow URL ที่ควรใช้กับรหัสพาร์ทนี้ (เทียบแบบตัวพิมพ์ใหญ่ทั้งหมด) จากรายการพาร์ทที่
 * โหลดมาแล้ว (ส่งเข้ามาเป็น $partsList เพื่อเลี่ยงการอ่านไฟล์ data/parts.json ซ้ำหลายรอบ
 * ในคำขอเดียวกัน) ถ้าพาร์ทนั้นไม่ได้ตั้ง workflow_url เฉพาะไว้ (เป็น null/ว่าง) จะคืนค่า
 * Workflow เริ่มต้น (ROBOFLOW_WORKFLOW_URL) แทน
 */
function getWorkflowUrlForPart($partCode, $partsList) {
    $key = strtoupper(trim($partCode));
    foreach ($partsList as $p) {
        if (isset($p['code']) && strtoupper($p['code']) === $key) {
            return !empty($p['workflow_url']) ? $p['workflow_url'] : ROBOFLOW_WORKFLOW_URL;
        }
    }
    return ROBOFLOW_WORKFLOW_URL;
}

/**
 * ตรวจว่ารหัสพาร์ทนี้ต้องส่งรูปแบบ "url" ให้ Roboflow แทน base64 หรือไม่
 */
function partUsesUrlInput($partCode) {
    global $PARTS_USE_URL_INPUT;
    $key = strtoupper(trim($partCode));
    foreach ($PARTS_USE_URL_INPUT as $code) {
        if (strtoupper(trim($code)) === $key) {
            return true;
        }
    }
    return false;
}

// Part ที่อยู่ใน $PARTS_USE_URL_INPUT จะถูกบังคับให้ส่งรูปเป็น URL
// ส่วน Part อื่นจะส่งเป็น base64
// ต้องแน่ใจว่า URL ของ uploads/ สามารถเข้าถึงได้จากอินเทอร์เน็ตภายนอก
// ถ้าต้องการกำหนดโดเมนสาธารณะเอง (แทนการเดาอัตโนมัติจาก request) ให้ใส่ค่าที่นี่
define('PUBLIC_BASE_URL', '');

// โฟลเดอร์เก็บไฟล์ต่าง ๆ (ต้อง chmod ให้เขียนได้ เช่น 755 หรือ 775)
define('DATA_FILE', __DIR__ . '/data/inspections.json');
define('PARTS_FILE', __DIR__ . '/data/parts.json');
define('MATERIALS_FILE', __DIR__ . '/data/materials.json');
define('SETTINGS_FILE', __DIR__ . '/data/settings.json');
define('EXCEL_REPORT_FILE', __DIR__ . '/data/latest_report.xlsx');
define('UPLOADS_DIR', __DIR__ . '/uploads');
define('MATERIAL_DIR', __DIR__ . '/material');
define('BARCODE_TRAINING_DIR', __DIR__ . '/training_data/barcode');

// บังคับ timezone ของทุก date()/time() ในไฟล์นี้ให้เป็นเวลาประเทศไทย (Asia/Bangkok, UTC+7)
// เสมอ ไม่ว่า hosting/เซิร์ฟเวอร์จะตั้งค่า default timezone ไว้เป็นอะไรก็ตาม (เช่น UTC) —
// ป้องกันปัญหาเวลาที่บันทึกลง inspections.json/materials.json คลาดเคลื่อนจากเวลาไทยจริง
date_default_timezone_set('Asia/Bangkok');

// ---------------------------------------------------------------------------
// ดาวน์โหลดไฟล์สำรองข้อมูลทั้งหมด (ZIP) — เรียกผ่าน GET ธรรมดา (ลิงก์/window.location)
// แยกออกจาก flow JSON ปกติทั้งหมดด้านล่าง เพราะต้องตอบกลับเป็นไฟล์ binary (ZIP) ไม่ใช่ JSON
// ต้องเช็คและ exit ตรงนี้ "ก่อน" ตั้ง Content-Type เป็น JSON และก่อนอ่าน php://input ด้านล่าง
// (คำขอ GET ไม่มี JSON body ส่งมาอยู่แล้ว ถ้าปล่อยให้ไหลลงไปจนถึงจุดตรวจ JSON จะ error ทันที)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export_backup') {
    handleExportBackup();
    exit;
}

// ดาวน์โหลดไฟล์ Excel ล่าสุด (สร้าง/อัปเดตล่าสุดโดย regenerateExcelReport() ท้ายไฟล์ ตอนผู้ใช้
// กดปุ่ม "บันทึกข้อมูล" — ดู endpoint save_to_excel) ผ่านลิงก์ตายตัวเดียวกันเสมอ
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download_latest_excel') {
    handleDownloadLatestExcel();
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ---------------------------------------------------------------------------
// กันเคส "Unexpected end of JSON input" ฝั่งหน้าเว็บ: ถ้าสคริปต์นี้ค้าง/พัง/timeout
// กลางทาง ปกติ PHP จะไม่ตอบอะไรกลับมาเลย (body ว่างเปล่า) ทำให้ front-end อ่านไม่ออก
// โค้ดด้านล่างนี้ดักไว้ ให้ยังไงก็ตอบเป็น JSON เสมอ พร้อมข้อความ error ที่อ่านออก
// ---------------------------------------------------------------------------
set_time_limit(90);            // กันสคริปต์ถูกตัดตอนเงียบ ๆ ก่อนจะทันตอบ error กลับไป (ค่า default มักแค่ 30 วิ)
ini_set('display_errors', '0'); // ปิดไม่ให้ PHP echo error แบบ HTML ปนออกมาในผลลัพธ์ (ให้ error_log แทน)

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // ถ้ายังไม่มีอะไรถูก echo ออกไปก่อนหน้านี้เลย (เช่น fatal error ก่อนถึงจุด echo ผลลัพธ์)
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'error' => 'เซิร์ฟเวอร์เกิดข้อผิดพลาดร้ายแรงระหว่างประมวลผล (PHP fatal error) หรือสคริปต์ทำงานนานเกินกำหนด',
            'detail' => $err['message'] . ' (' . $err['file'] . ':' . $err['line'] . ')'
        ]);
    }
});

// ---------------------------------------------------------------------------
// อ่าน request body (JSON) จากฝั่งหน้าเว็บ
// ---------------------------------------------------------------------------
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'รูปแบบข้อมูลที่ส่งมาไม่ถูกต้อง (ไม่ใช่ JSON)']);
    exit;
}

// ---------------------------------------------------------------------------
// กรณีที่ 0: จัดการรายชื่อพาร์ท (Part Master) — ทำก่อนเช็ค "part" field ด้านล่าง เพราะ
// endpoint กลุ่มนี้ใช้ field "code" แทน (ไม่ใช่ "part" ที่ใช้ตอนวิเคราะห์/บันทึกผลตรวจ)
// และ get_parts ไม่ต้องมีรหัสพาร์ทเจาะจงเลยด้วยซ้ำ (แค่ขอดูรายการทั้งหมด)
// ---------------------------------------------------------------------------
$requestType = isset($input['type']) ? $input['type'] : '';

if ($requestType === 'get_parts') {
    echo json_encode(['success' => true, 'parts' => loadPartsList()]);
    exit;
}

if ($requestType === 'get_materials') {
    echo json_encode(['success' => true, 'materials' => loadMaterials()]);
    exit;
}

if ($requestType === 'get_settings') {
    echo json_encode(['success' => true, 'settings' => loadSettings()]);
    exit;
}

if ($requestType === 'save_settings') {
    // ตอนนี้มีแค่ค่าเดียวที่ปรับได้ผ่าน UI (เกณฑ์ความมั่นใจ OK/NG) แต่เขียนให้รับ/merge
    // เป็น object กลางๆ ไว้ เผื่ออนาคตมีค่าตั้งค่าอื่นเพิ่มโดยไม่ต้องเปลี่ยนโครงสร้าง endpoint
    $confidenceRaw = isset($input['confidence_threshold']) ? $input['confidence_threshold'] : null;

    if ($confidenceRaw === null || !is_numeric($confidenceRaw)) {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาระบุค่าเกณฑ์ความมั่นใจเป็นตัวเลข']);
        exit;
    }
    $confidenceValue = floatval($confidenceRaw);
    // รับค่าเป็นเปอร์เซ็นต์ (1-100) จากหน้าเว็บ เก็บเป็นสัดส่วน (0.01-1.00) ในไฟล์ ให้ตรงกับ
    // รูปแบบเดิมที่ CONFIDENCE_THRESHOLD เคยเป็น (0.80) ทุกจุดที่ใช้เทียบ >= ด้านล่างไฟล์
    if ($confidenceValue > 1) {
        $confidenceValue = $confidenceValue / 100;
    }
    if ($confidenceValue <= 0 || $confidenceValue > 1) {
        http_response_code(400);
        echo json_encode(['error' => 'เกณฑ์ความมั่นใจต้องอยู่ระหว่าง 1-100%']);
        exit;
    }

    $result = readModifyWriteJsonFile(SETTINGS_FILE, function (&$data) use ($confidenceValue) {
        if (!is_array($data)) {
            $data = getDefaultSettings();
        }
        $data['confidence_threshold'] = round($confidenceValue, 4);
        return true;
    });

    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกการตั้งค่าไม่สำเร็จ (ล็อกไฟล์ไม่ได้ หรือเขียนไฟล์ไม่สำเร็จ)']);
        exit;
    }

    echo json_encode(['success' => true, 'settings' => $result]);
    exit;
}

if ($requestType === 'import_backup') {
    handleImportBackup($input);
    exit;
}

// บันทึกข้อมูลปัจจุบันทั้งหมดเข้าไฟล์ Excel หลักด้วยตนเอง (ผู้ใช้เป็นคนกำหนดเองว่าจะกดเมื่อไหร่
// แทนที่จะสร้างอัตโนมัติทุกครั้งที่สแกน — ผู้ใช้ต้องการควบคุมจังหวะการอัปเดตไฟล์เอง)
if ($requestType === 'save_to_excel') {
    $records = loadRecords();
    $ok = regenerateExcelReport($records);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกข้อมูลลง Excel ไม่สำเร็จ (ดู error log ฝั่งเซิร์ฟเวอร์สำหรับรายละเอียด)']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'message' => 'บันทึกข้อมูลลง Excel เรียบร้อย (' . count($records) . ' รายการ)',
        'record_count' => count($records),
        'saved_at' => date('Y-m-d H:i:s')
    ]);
    exit;
}

// เช็คสถานะไฟล์ Excel หลักล่าสุด (มีไฟล์อยู่ไหม, อัปเดตล่าสุดเมื่อไหร่) ใช้แสดงในหน้าเว็บ
// เพื่อให้ผู้ใช้รู้ว่าไฟล์ที่จะโหลดตอนนี้ตรงกับข้อมูลล่าสุดหรือยัง (เพราะตอนนี้ต้องกด "บันทึก
// ข้อมูล" เองแล้ว ไม่ได้อัปเดตอัตโนมัติทุกครั้งที่สแกนเหมือนเดิม)
if ($requestType === 'get_excel_status') {
    if (file_exists(EXCEL_REPORT_FILE)) {
        echo json_encode([
            'success' => true,
            'exists' => true,
            'updated_at' => date('Y-m-d H:i:s', filemtime(EXCEL_REPORT_FILE))
        ]);
    } else {
        echo json_encode(['success' => true, 'exists' => false, 'updated_at' => null]);
    }
    exit;
}

if ($requestType === 'save_part') {
    $code = isset($input['code']) ? strtoupper(trim($input['code'])) : '';
    $workflowUrl = isset($input['workflow_url']) ? trim($input['workflow_url']) : '';
    // original_code: ใช้ตอน "แก้ไข" รหัสพาร์ทเดิมให้เป็นรหัสใหม่ (ไม่ใช่แค่แก้ workflow_url)
    // ถ้าไม่ได้ส่งมาหรือเท่ากับ code ใหม่ ถือว่าเป็นการเพิ่ม/แก้ไขปกติ ไม่ใช่การเปลี่ยนรหัส
    $originalCode = isset($input['original_code']) ? strtoupper(trim($input['original_code'])) : '';

    if ($code === '') {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาระบุรหัสพาร์ท']);
        exit;
    }
    // อนุญาตเฉพาะตัวอักษร A-Z, ตัวเลข, และขีด (-) เท่านั้น — กันอักขระแปลกปลอม/HTML หลุดเข้าไป
    // ใน parts.json (รหัสพาร์ทค่านี้จะถูกใช้ประกอบชื่อโฟลเดอร์ไฟล์ด้วย ดู sanitizeForPath)
    if (!preg_match('/^[A-Z0-9\-]+$/', $code)) {
        http_response_code(400);
        echo json_encode(['error' => 'รหัสพาร์ทต้องเป็นตัวอักษร A-Z, ตัวเลข, และเครื่องหมาย - เท่านั้น']);
        exit;
    }
    if ($workflowUrl !== '' && !filter_var($workflowUrl, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Workflow URL ไม่ถูกต้อง (ต้องเป็น URL เต็ม หรือเว้นว่างไว้เพื่อใช้ Workflow เริ่มต้น)']);
        exit;
    }

    $result = readModifyWriteJsonFile(PARTS_FILE, function (&$data) use ($code, $workflowUrl, $originalCode) {
        if ($data === null) {
            $data = getDefaultPartsSeed();
        }

        // กรณีแก้ไขรหัสพาร์ทเดิมให้เป็นรหัสใหม่: ลบตัวเดิมออกก่อน (ถ้ามี) แล้วค่อยเพิ่ม/แก้
        // ตัวใหม่ต่อด้านล่าง
        if ($originalCode !== '' && $originalCode !== $code) {
            $data = array_values(array_filter($data, function ($p) use ($originalCode) {
                return strtoupper($p['code']) !== $originalCode;
            }));
        }

        // กันรหัสซ้ำ: ถ้ามี code นี้อยู่แล้วในรายการ ให้แก้ไข workflow_url ของตัวเดิมแทนที่จะ
        // เพิ่มซ้ำเป็นสองแถว
        $found = false;
        foreach ($data as &$p) {
            if (strtoupper($p['code']) === $code) {
                $p['workflow_url'] = $workflowUrl !== '' ? $workflowUrl : null;
                $found = true;
                break;
            }
        }
        unset($p);

        if (!$found) {
            $data[] = ['code' => $code, 'workflow_url' => $workflowUrl !== '' ? $workflowUrl : null];
        }

        return true;
    });

    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกรายชื่อพาร์ทไม่สำเร็จ (ล็อกไฟล์ไม่ได้ หรือเขียนไฟล์ไม่สำเร็จ)']);
        exit;
    }

    echo json_encode(['success' => true, 'parts' => $result]);
    exit;
}

if ($requestType === 'delete_part') {
    $code = isset($input['code']) ? strtoupper(trim($input['code'])) : '';
    if ($code === '') {
        http_response_code(400);
        echo json_encode(['error' => 'กรุณาระบุรหัสพาร์ทที่จะลบ']);
        exit;
    }

    $result = readModifyWriteJsonFile(PARTS_FILE, function (&$data) use ($code) {
        if ($data === null) {
            $data = getDefaultPartsSeed();
        }
        $data = array_values(array_filter($data, function ($p) use ($code) {
            return strtoupper($p['code']) !== $code;
        }));
        return true;
    });

    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'ลบรหัสพาร์ทไม่สำเร็จ (ล็อกไฟล์ไม่ได้ หรือเขียนไฟล์ไม่สำเร็จ)']);
        exit;
    }

    echo json_encode(['success' => true, 'parts' => $result]);
    exit;
}

// ---------------------------------------------------------------------------
// จากจุดนี้ลงไป (barcode_training / update_ng_reason / วิเคราะห์ชิ้นงาน) ทุก request
// ต้องมี field "part" เสมอ และต้องเป็นรหัสพาร์ทที่มีอยู่จริงใน data/parts.json เท่านั้น
// ---------------------------------------------------------------------------
$partCode = isset($input['part']) ? trim($input['part']) : '';

if ($partCode === '') {
    http_response_code(400);
    echo json_encode(['error' => 'ไม่พบรหัสพาร์ท (part)']);
    exit;
}

// ตรวจสอบว่ารหัสพาร์ทนี้มีอยู่จริงในระบบหรือไม่ (เทียบกับ data/parts.json) — เดิม proxy.php
// เชื่อค่า "part" ที่ส่งมาตรงๆ โดยไม่เช็คเลย ทำให้ยิง request ตรงมาใส่รหัสพาร์ทอะไรก็ได้
// (แม้แต่ข้อความที่มี HTML/script) แล้วถูกบันทึกลง inspections.json ไปแสดงผลในหน้า
// "ข้อมูลชิ้นงาน" แบบไม่ผ่านการ escape — เพิ่มการเช็คนี้เพื่ออุดช่องโหว่ดังกล่าว
$partsList = loadPartsList();
$isValidPart = false;
foreach ($partsList as $p) {
    if (isset($p['code']) && strtoupper($p['code']) === strtoupper($partCode)) {
        $isValidPart = true;
        break;
    }
}
if (!$isValidPart) {
    http_response_code(400);
    echo json_encode(['error' => "ไม่พบรหัสพาร์ท \"{$partCode}\" ในระบบ กรุณาตรวจสอบรหัสอีกครั้ง"]);
    exit;
}

// ---------------------------------------------------------------------------
// กรณีที่ 1: บันทึกรูป QR/บาร์โค้ด เป็นชุดข้อมูลฝึกโมเดล (ไม่วิเคราะห์)
// ---------------------------------------------------------------------------
if (isset($input['type']) && $input['type'] === 'barcode_training') {
    $imageDataUrl = isset($input['image']) ? $input['image'] : '';

    if ($imageDataUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรูปภาพ (image)']);
        exit;
    }

    $binary = decodeDataUrlImage($imageDataUrl);
    if ($binary === null) {
        http_response_code(400);
        echo json_encode(['error' => 'ถอดรหัสรูปภาพไม่สำเร็จ']);
        exit;
    }

    $partDir = BARCODE_TRAINING_DIR . '/' . sanitizeForPath($partCode);
    if (!is_dir($partDir)) {
        mkdir($partDir, 0775, true);
    }

    $filename = date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.jpg';
    $fullPath = $partDir . '/' . $filename;
    file_put_contents($fullPath, $binary);

    echo json_encode([
        'success' => true,
        'message' => 'บันทึกรูปบาร์โค้ด/QR สำหรับเทรนโมเดลเรียบร้อย',
        'saved_path' => 'training_data/barcode/' . sanitizeForPath($partCode) . '/' . $filename
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// กรณีที่ 1.5: บันทึกสาเหตุ NG ย้อนหลัง เข้า record ที่มีอยู่แล้ว (ใช้ id อ้างอิง)
//
// ทำงานแยกจากการวิเคราะห์หลัก เพราะตอนที่ระบบรู้ผล NG (จบ request แรกไปแล้ว) ผู้ตรวจถึง
// จะเลือกสาเหตุได้ (ต้องเห็นผลก่อนถึงจะรู้ว่าจะเลือกอะไร) จึงต้องเป็น request ที่ 2 แยกต่างหาก
// มาอัปเดต record เดิมที่บันทึกไปแล้วจาก request แรก
//
// เก็บเป็นรหัสสาเหตุ (ไม่ใช่ข้อความอิสระ) เพื่อให้เอาไปสรุป/กรอง/ทำกราฟต่อได้ง่าย ยกเว้น
// reason = "other" ที่ยอมให้แนบข้อความเพิ่มเติมได้ผ่าน reason_note (จำกัดความยาวกันข้อมูลบวม)
// ---------------------------------------------------------------------------
if (isset($input['type']) && $input['type'] === 'update_ng_reason') {
    $recordId = isset($input['id']) ? intval($input['id']) : 0;
    $reason = isset($input['reason']) ? trim($input['reason']) : '';
    $reasonNote = isset($input['reason_note']) ? trim($input['reason_note']) : '';

    if ($recordId <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรหัสรายการ (id) ที่จะบันทึกสาเหตุ']);
        exit;
    }

    // whitelist รหัสสาเหตุที่ยอมรับ — กันข้อมูลแปลกปลอมหลุดเข้าไปใน inspections.json
    // (ตรงกับตัวเลือกที่ index.html แสดงให้ผู้ตรวจกดเลือกทุกประการ)
    $allowedReasons = ['real_defect', 'blurry_photo', 'poor_lighting', 'bad_angle', 'other'];
    if (!in_array($reason, $allowedReasons, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'สาเหตุ NG ที่ระบุไม่ถูกต้อง']);
        exit;
    }

    $updatedRecord = updateRecordWithLock($recordId, function (&$record) use ($reason, $reasonNote) {
        $record['ng_reason'] = $reason;
        $record['ng_reason_note'] = ($reason === 'other' && $reasonNote !== '') ? mb_substr($reasonNote, 0, 300) : null;
    });

    if ($updatedRecord === null) {
        http_response_code(404);
        echo json_encode(['error' => 'ไม่พบรายการที่ต้องการบันทึกสาเหตุ หรือบันทึกไม่สำเร็จ']);
        exit;
    }

    echo json_encode(['success' => true, 'record' => $updatedRecord]);
    exit;
}

// ---------------------------------------------------------------------------
// กรณีที่ 1.5: บันทึก/อัปเดตรูปตัวอย่าง (Material) ของพาร์ทนี้
// ต่างจาก uploads/ ของหน้าสแกน (ที่ตั้งชื่อไฟล์ตามเวลาเพื่อเก็บประวัติทุกครั้งที่สแกน)
// ตรงที่รูปตัวอย่างใช้ชื่อไฟล์คงที่ต่อฝั่ง (front.jpg/back.jpg/barcode.jpg) แล้ว "ทับ"
// ไฟล์เดิมเสมอ เพราะหน้า Material ต้องการแสดง "รูปตัวอย่างล่าสุดที่ตั้งไว้" เพียงชุดเดียว
// ต่อพาร์ท ไม่ใช่ประวัติการสแกน — metadata (updated_at) เก็บแยกไว้ใน materials.json
// เพื่อให้หน้าเว็บรู้ว่าพาร์ทไหนตั้งรูปตัวอย่างไว้แล้วบ้าง และอัปเดตล่าสุดเมื่อไหร่
// ---------------------------------------------------------------------------
if (isset($input['type']) && $input['type'] === 'save_material_image') {
    $side = isset($input['side']) ? trim($input['side']) : '';
    $imageDataUrl = isset($input['image']) ? $input['image'] : '';

    if (!in_array($side, ['front', 'back', 'barcode'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'ระบุตำแหน่งรูปไม่ถูกต้อง (ต้องเป็น front, back หรือ barcode)']);
        exit;
    }
    if ($imageDataUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบรูปภาพ (image)']);
        exit;
    }

    $binary = decodeDataUrlImage($imageDataUrl);
    if ($binary === null) {
        http_response_code(400);
        echo json_encode(['error' => 'ถอดรหัสรูปภาพไม่สำเร็จ']);
        exit;
    }

    $partDir = MATERIAL_DIR . '/' . sanitizeForPath($partCode);
    if (!is_dir($partDir)) {
        mkdir($partDir, 0775, true);
    }
    $fullPath = $partDir . '/' . $side . '.jpg';
    file_put_contents($fullPath, $binary);
    $relPath = 'material/' . sanitizeForPath($partCode) . '/' . $side . '.jpg';

    $result = readModifyWriteJsonFile(MATERIALS_FILE, function (&$data) use ($partCode, $side, $relPath) {
        if ($data === null) {
            $data = [];
        }

        $found = false;
        foreach ($data as &$m) {
            if (isset($m['part_code']) && strtoupper($m['part_code']) === strtoupper($partCode)) {
                $m['image_path_' . $side] = $relPath;
                $m['updated_at'] = date('Y-m-d H:i:s'); // เวลาไทย (date_default_timezone_set ด้านบนไฟล์)
                $found = true;
                break;
            }
        }
        unset($m);

        if (!$found) {
            $newEntry = [
                'part_code' => $partCode,
                'image_path_front' => null,
                'image_path_back' => null,
                'image_path_barcode' => null,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            $newEntry['image_path_' . $side] = $relPath;
            $data[] = $newEntry;
        }

        return true;
    });

    if ($result === null) {
        http_response_code(500);
        echo json_encode(['error' => 'บันทึกรูปตัวอย่างไม่สำเร็จ (ล็อกไฟล์ไม่ได้ หรือเขียนไฟล์ไม่สำเร็จ)']);
        exit;
    }

    echo json_encode(['success' => true, 'materials' => $result]);
    exit;
}

// ---------------------------------------------------------------------------
// กรณีที่ 2: ตรวจสอบชิ้นงานจริง (ด้านหน้า + ด้านหลัง + บาร์โค้ด/QR)
// ---------------------------------------------------------------------------

// เกณฑ์ความมั่นใจขั้นต่ำของ Roboflow ที่จะถือว่า "ผ่าน (OK)" — ใช้ค่าเดียวกันทั้ง 3 ฝั่ง
// (หน้า/หลัง/บาร์โค้ด-QR) เดิม hardcode เป็น define('CONFIDENCE_THRESHOLD', 0.80) ตรงนี้
// ย้ายไปเก็บเป็นไฟล์ data/settings.json แทน (ดู loadSettings()/getDefaultSettings() ท้าย
// ไฟล์) เพื่อให้ปรับได้จากหน้า "จัดการพาร์ท" โดยไม่ต้องแก้โค้ด/deploy ใหม่ทุกครั้งที่อยาก
// ทดสอบเกณฑ์ที่ต่างไป (ค่าเริ่มต้นยังคงเป็น 0.80 เท่าเดิมถ้ายังไม่เคยปรับ)
$confidenceThreshold = loadSettings()['confidence_threshold'];

$images = isset($input['images']) ? $input['images'] : null;

// รองรับของเดิมด้วย เผื่อมี request ที่ส่งรูปเดี่ยวแบบเก่ามา (field "image")
if ($images === null && isset($input['image'])) {
    $images = ['front' => $input['image']];
}

if (!is_array($images) || empty($images['front'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ไม่พบรูปภาพสำหรับวิเคราะห์ (images.front)']);
    exit;
}

// ด้านที่ต้องส่งวิเคราะห์กับ Roboflow: front, back (บังคับ) และ barcode (ไม่บังคับ — ส่งเมื่อมีรูปมาเท่านั้น)
// barcode ถูกวิเคราะห์ผ่าน Roboflow เหมือนกับ front/back ทุกประการ (เทรนด้วยโมเดลเดียวกัน)
// เพื่อเอาไว้แสดงกรอบ+% ความมั่นใจในหน้าเว็บเท่านั้น — "ไม่" ถูกนำผลไปรวมคิดสถานะ OK/NG
// ของชิ้นงาน (ดูส่วนคำนวณสถานะด้านล่าง ซึ่งนับเฉพาะ front/back)
// ลำดับใน $analysisOrder (front, back, barcode) จะตรงกับลำดับ result.outputs ที่ index.html นำไปใช้
// (outputs[0]=หน้า, outputs[1]=หลัง, outputs[2]=บาร์โค้ด ถ้ามี)
$analysisSides = [];
$analysisOrder = [];

foreach (['front', 'back', 'barcode'] as $sideKey) {
    if (!empty($images[$sideKey])) {
        $analysisSides[$sideKey] = $images[$sideKey];
        $analysisOrder[] = $sideKey;
    }
}

$outputs = [];
$outputsBySide = []; // เก็บผลแยกตามฝั่ง (front/back/barcode) ไว้ใช้คิดสถานะเฉพาะ front+back ด้านล่าง
$savedImagePaths = [
    'front' => null,
    'back' => null,
    'barcode' => null
];

// รหัสพาร์ทนี้ต้องใช้ Roboflow Workflow ตัวไหน (ค่าเริ่มต้นคือ custom-workflow-2-4 ยกเว้น
// พาร์ทที่ตั้ง workflow_url เฉพาะไว้ใน data/parts.json ผ่านหน้า Part Master) — ใช้ workflow
// เดียวกันกับ front/back/barcode ทั้งหมด ส่ง $partsList ที่โหลดไว้แล้วด้านบนเข้าไปเลย
// (เลี่ยงอ่านไฟล์ parts.json ซ้ำอีกรอบในคำขอเดียวกัน)
$workflowUrl = getWorkflowUrlForPart($partCode, $partsList);

// รหัสพาร์ทนี้ต้องส่งรูปเป็น "url" แทน base64 หรือไม่ (ดู $PARTS_USE_URL_INPUT ด้านบนไฟล์)
$useUrlInput = partUsesUrlInput($partCode);

foreach ($analysisOrder as $sideKey) {
    $dataUrl = $analysisSides[$sideKey];

    // เก็บรูปไว้ในเครื่อง เพื่อให้หน้า "ข้อมูลชิ้นงาน" / "Material" แสดงรูปย้อนหลังได้
    // (จำเป็นเสมอ ไม่ว่าจะส่งวิเคราะห์แบบ base64 หรือ url เพราะฝั่ง url ต้องใช้ path
    //  ของไฟล์ที่บันทึกไว้นี้มาประกอบเป็น public URL ด้วย)
    $savedRelPath = saveUploadImage($dataUrl, $partCode, $sideKey);
    $savedImagePaths[$sideKey] = $savedRelPath;

    if ($savedRelPath === null) {
        http_response_code(400);
        echo json_encode(['error' => "บันทึกไฟล์รูปไม่สำเร็จ (ฝั่ง {$sideKey})"]);
        exit;
    }

    // ถ้ารหัสพาร์ทนี้ต้องส่งแบบ "url" (เช่น JB3Z17A870B / custom-workflow-5) ให้ประกอบ
    // public URL จากไฟล์ที่เพิ่งบันทึกไว้ แล้วส่งให้ Roboflow ไปดึงเอง
    // มิฉะนั้น (พาร์ทอื่น ๆ) ยังคงส่งเป็น base64 ตรง ๆ เหมือนเดิม เพราะ hosting นี้เคย
    // เจอปัญหา Roboflow ดึง URL สาธารณะไม่ได้ ("Remote end closed connection without response")
    $publicImageUrl = null;
    if ($useUrlInput) {
        $publicImageUrl = getPublicBaseUrl() . '/' . ltrim($savedRelPath, '/');
    }

    $roboflowResult = callRoboflowWorkflow($dataUrl, $workflowUrl, $useUrlInput, $publicImageUrl);

    if (isset($roboflowResult['__error'])) {
        http_response_code(502);
        $detail = '';
        if ($roboflowResult['reason'] === 'curl') {
            $detail = "การเชื่อมต่อ (cURL) ล้มเหลว errno={$roboflowResult['curl_errno']}: {$roboflowResult['curl_error']}";
        } elseif ($roboflowResult['reason'] === 'http_status') {
            $detail = "Roboflow ตอบกลับ HTTP {$roboflowResult['http_code']}\nเนื้อหา: {$roboflowResult['body']}";
        } else {
            $detail = "Roboflow ตอบกลับ HTTP {$roboflowResult['http_code']} แต่ไม่ใช่ JSON\nเนื้อหา: {$roboflowResult['body']}";
        }
        echo json_encode(['error' => "เรียก Roboflow ไม่สำเร็จ (ฝั่ง {$sideKey})", 'detail' => $detail]);
        exit;
    }

    // roboflow เดิมตอบกลับเป็น { outputs: [ { predictions: { predictions: [...] } } ] }
    // เราดึงตัวแรกออกมา เพื่อไปประกอบใหม่เป็น outputs[0]=front, outputs[1]=back, outputs[2]=barcode
    $singleOutput = (isset($roboflowResult['outputs']) && isset($roboflowResult['outputs'][0]))
        ? $roboflowResult['outputs'][0]
        : ['predictions' => ['predictions' => []]];

    $outputs[] = $singleOutput;
    $outputsBySide[$sideKey] = $singleOutput;
}

// ---------------------------------------------------------------------------
// สรุปสถานะรวม เพื่อบันทึกลง data/inspections.json
//
// !! นับเฉพาะผลของ "front" และ "back" เท่านั้น !! ต้องเจอคลาสที่ Roboflow จำแนกตรงกับ
// รหัสพาร์ท "และ" มั่นใจ >= 80% (CONFIDENCE_THRESHOLD) ถึงจะนับเป็น OK ของฝั่งนั้น
// (เจอคลาสตรงแต่มั่นใจต่ำกว่า 80% -> NG, ไม่เจอคลาสตรงเลย -> NO_DETECTION)
//
// ผลของ "barcode" (ถ้ามีถ่ายรูปมา) จะ "ไม่" ถูกนำมารวมคิด total_detections / ok_count /
// ng_count / overall_status เด็ดขาด — ใช้กรอบ+% จาก Roboflow แสดงเป็นข้อมูลอ้างอิงเสริม
// ในหน้าเว็บเท่านั้น (เหมือนหน้า/หลังทุกประการในแง่การแสดงผล แต่ไม่นับรวมสถานะ)
//
// รวมเป็น overall_status ด้วยลำดับความสำคัญ: มี NG ที่ไหน -> NG, ไม่มี NG แต่มี NO_DETECTION
// ที่ไหน -> NO_DETECTION, ผ่านหมดทุกฝั่งที่ประเมิน -> OK
// ---------------------------------------------------------------------------
$expectedClass = normalizeClassCode($partCode);
$totalDetections = 0;
$okCount = 0;
$ngCount = 0;
$statusList = []; // สถานะของแต่ละฝั่งที่ถูกประเมิน (front, back เท่านั้น — ไม่รวม barcode)

foreach (['front', 'back'] as $sideKey) {
    if (!isset($outputsBySide[$sideKey])) {
        continue;
    }
    $output = $outputsBySide[$sideKey];
    $predictions = isset($output['predictions']['predictions']) ? $output['predictions']['predictions'] : [];
    $sideStatus = 'NO_DETECTION';

    foreach ($predictions as $pred) {
        $totalDetections++;
        $detectedClass = isset($pred['class']) ? normalizeClassCode($pred['class']) : '';
        $confidence = isset($pred['confidence']) ? floatval($pred['confidence']) : 0;

        if ($detectedClass !== $expectedClass) {
            continue; // ไม่ตรงพาร์ท ไม่นับเป็น ok/ng
        }

        if ($confidence >= $confidenceThreshold) {
            $okCount++;
            if ($sideStatus !== 'NG') {
                $sideStatus = 'OK';
            }
        } else {
            $ngCount++;
            $sideStatus = 'NG';
        }
    }

    $statusList[] = $sideStatus;
}

// --- รวมเป็นสถานะภาพรวม ---
if (in_array('NG', $statusList, true)) {
    $overallStatus = 'NG';
} elseif (in_array('NO_DETECTION', $statusList, true)) {
    $overallStatus = 'NO_DETECTION';
} else {
    $overallStatus = 'OK';
}

// ---------------------------------------------------------------------------
// บันทึกผลลง data/inspections.json (append record ใหม่)
//
// ใช้ appendRecordWithLock() แทนเดิม (loadRecords() + saveRecords() แยกกันโดยไม่ล็อกไฟล์)
// เพราะของเดิมมีช่วงเวลา (window) ระหว่างอ่านไฟล์กับเขียนไฟล์ ถ้ามีหลายสถานีตรวจสแกน
// พร้อมกันในเวลาใกล้เคียงกันมาก (เคสจริงในโรงงานที่มีหลายจุดตรวจ) request สองอันอาจอ่าน
// ไฟล์เดิม (ก่อนอีกฝั่งเขียนเสร็จ) แล้วต่างฝั่งต่างเขียนทับกันเอง ทำให้ record ของฝั่งใด
// ฝั่งหนึ่งหายไปทั้งที่วิเคราะห์ผ่าน Roboflow และบันทึกไฟล์รูปสำเร็จแล้ว
// ---------------------------------------------------------------------------
$newRecord = appendRecordWithLock(function ($records) use ($partCode, $totalDetections, $okCount, $ngCount, $overallStatus, $savedImagePaths) {
    $newId = 1;
    foreach ($records as $r) {
        if (isset($r['id']) && $r['id'] >= $newId) {
            $newId = $r['id'] + 1;
        }
    }

    return [
        'id' => $newId,
        'datetime' => date('Y-m-d H:i:s'),
        'part_code' => $partCode,
        'total_detections' => $totalDetections,
        'ok_count' => $okCount,
        'ng_count' => $ngCount,
        'overall_status' => $overallStatus,
        // เก็บรูปทั้ง 3 อย่างของ record นี้ไว้ครบ เพื่อให้หน้า "ข้อมูลชิ้นงาน" และ "Material"
        // แสดงได้ทั้งหน้า/หลัง/บาร์โค้ด
        'image_path' => $savedImagePaths['front'],
        'image_path_back' => $savedImagePaths['back'],
        'image_path_barcode' => $savedImagePaths['barcode'],
        // สาเหตุ NG (ถ้ามี) — ยังไม่รู้ตอนบันทึกครั้งแรก ผู้ตรวจจะเลือกทีหลังหลังเห็นผลบนจอ
        // ผ่าน endpoint update_ng_reason (ดูด้านบนไฟล์) ค่าเริ่มต้นเป็น null เสมอ
        'ng_reason' => null,
        'ng_reason_note' => null
    ];
});

if ($newRecord === null) {
    http_response_code(500);
    echo json_encode(['error' => 'บันทึกผลการตรวจสอบไม่สำเร็จ (ล็อกไฟล์ข้อมูลไม่ได้ หรือเขียนไฟล์ไม่สำเร็จ) กรุณาลองใหม่อีกครั้ง']);
    exit;
}

// ---------------------------------------------------------------------------
// ตอบกลับไปให้ index.html ในรูปแบบที่ front-end คาดหวัง
// (overall_status ส่งกลับไปด้วย เพื่อให้หน้าเว็บใช้ค่าเดียวกับที่บันทึกจริง แทนที่จะคำนวณ
// เองซ้ำฝั่งไคลเอนต์แล้วอาจเพี้ยนไม่ตรงกัน)
// ---------------------------------------------------------------------------
echo json_encode([
    'success' => true,
    'outputs' => $outputs,
    'overall_status' => $overallStatus,
    // ส่ง id ของ record ที่เพิ่งบันทึกกลับไปด้วย ให้ front-end ใช้อ้างอิงตอนผู้ตรวจเลือก
    // สาเหตุ NG ย้อนหลัง (เรียก update_ng_reason ในการ request ครั้งถัดไป)
    'id' => $newRecord['id']
]);
exit;


// =============================================================================
// ฟังก์ชันช่วยเหลือ
// =============================================================================

/**
 * หา URL สาธารณะ (scheme://host/path) ของโฟลเดอร์ที่ proxy.php นี้รันอยู่ โดยอัตโนมัติ
 * จากข้อมูล request ปัจจุบัน เพื่อเอาไปต่อกับ path รูปที่บันทึกไว้ (uploads/...)
 * แล้วส่งเป็น URL ให้ Roboflow ดึงไปดู — ไม่ต้องพึ่งการตั้งค่าโดเมนเอง
 * ถ้าตั้งค่า PUBLIC_BASE_URL ไว้เอง (ไม่ว่าง) จะใช้ค่านั้นแทนทันที
 */
function getPublicBaseUrl() {
    if (defined('PUBLIC_BASE_URL') && PUBLIC_BASE_URL !== '') {
        return PUBLIC_BASE_URL;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    $scheme = $isHttps ? 'https' : 'http';

    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // โฟลเดอร์ที่ proxy.php ตัวนี้อยู่ (ตัด /proxy.php ออก เหลือแค่ path ของโฟลเดอร์)
    $scriptDir = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '';
    $scriptDir = rtrim($scriptDir, '/');

    return $scheme . '://' . $host . $scriptDir;
}

/**
 * ถอดรหัส data URL แล้วบันทึกลงโฟลเดอร์ uploads/{partCode}/ พร้อมตั้งชื่อไฟล์ตาม $sideKey
 * (front / back / barcode) คืนค่า path สัมพัทธ์ (relative path) สำหรับใช้แสดงผลใน <img src>
 * คืนค่า null ถ้าถอดรหัสหรือบันทึกไฟล์ไม่สำเร็จ
 */
function saveUploadImage($dataUrl, $partCode, $sideKey) {
    $binary = decodeDataUrlImage($dataUrl);
    if ($binary === null) {
        return null;
    }

    $partDir = UPLOADS_DIR . '/' . sanitizeForPath($partCode);
    if (!is_dir($partDir)) {
        mkdir($partDir, 0775, true);
    }

    $filename = date('Ymd_His') . '_' . $sideKey . '_' . substr(md5(uniqid('', true)), 0, 6) . '.jpg';
    $fullPath = $partDir . '/' . $filename;
    file_put_contents($fullPath, $binary);

    return 'uploads/' . sanitizeForPath($partCode) . '/' . $filename;
}

/**
 * ส่งรูปไปยัง Roboflow Workflow API 1 รูป แล้วคืนค่าผลลัพธ์เป็น array
 *
 * $imageDataUrl   : data URL (base64) ของรูป ที่ได้จากเบราว์เซอร์ (ใช้เมื่อ $useUrlInput = false)
 * $workflowUrl    : Roboflow Workflow endpoint ที่จะเรียก
 * $useUrlInput    : true = ส่ง inputs.image เป็น {"type":"url","value": $publicImageUrl}
 *                   false = ส่งเป็น {"type":"base64","value": ...} (ค่าเริ่มต้น/เดิม)
 * $publicImageUrl : URL สาธารณะของรูป (ต้องใช้เมื่อ $useUrlInput = true)
 *
 * คืนค่าเป็น array ที่มี key '__error' ถ้าเรียกไม่สำเร็จ พร้อมรายละเอียดสาเหตุ
 */
function callRoboflowWorkflow($imageDataUrl, $workflowUrl = null, $useUrlInput = false, $publicImageUrl = null) {
    if ($workflowUrl === null) {
        $workflowUrl = ROBOFLOW_WORKFLOW_URL;
    }

    /*
     * Roboflow Inference API v1.5.0+
     *
     * API key ต้องส่งผ่าน HTTP header:
     * Authorization: Bearer YOUR_API_KEY
     *
     * ห้ามส่ง api_key ใน JSON body
     */

    if ($useUrlInput && !empty($publicImageUrl)) {
        // JB3Z17A870B
        // ส่งรูปแบบ URL ตาม Workflow ที่กำหนด
        $imageInput = [
            'type' => 'url',
            'value' => $publicImageUrl
        ];
    } else {
        // Part อื่นยังใช้ base64 เหมือนเดิม
        $imageInput = [
            'type' => 'base64',
            'value' => stripDataUrlPrefix($imageDataUrl)
        ];
    }

    $payload = json_encode([
        'inputs' => [
            'image' => $imageInput
        ]
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return [
            '__error' => true,
            'reason' => 'json_encode',
            'http_code' => 0,
            'body' => json_last_error_msg()
        ];
    }

    $ch = curl_init($workflowUrl);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,

        // Roboflow Inference API v1.5.0+
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . ROBOFLOW_API_KEY
        ],

        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 20,

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    $curlErrno = curl_errno($ch);

    curl_close($ch);

    if ($response === false || $curlErr) {
        error_log(
            'Roboflow call failed: ' .
            $curlErrno .
            ' - ' .
            $curlErr
        );

        return [
            '__error' => true,
            'reason' => 'curl',
            'curl_errno' => $curlErrno,
            'curl_error' => $curlErr
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log(
            'Roboflow HTTP ' .
            $httpCode .
            ': ' .
            $response
        );

        return [
            '__error' => true,
            'reason' => 'http_status',
            'http_code' => $httpCode,
            'body' => mb_substr($response, 0, 1000)
        ];
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded)) {
        return [
            '__error' => true,
            'reason' => 'invalid_json',
            'http_code' => $httpCode,
            'body' => mb_substr($response, 0, 1000)
        ];
    }

    return $decoded;
}

/**
 * ตัด prefix "data:image/jpeg;base64," ออก เหลือแต่ base64 ล้วน ๆ
 */
function stripDataUrlPrefix($dataUrl) {
    if (strpos($dataUrl, 'base64,') !== false) {
        return substr($dataUrl, strpos($dataUrl, 'base64,') + 7);
    }
    return $dataUrl;
}

/**
 * ถอดรหัส data URL (base64) ให้เป็นข้อมูลรูปภาพจริง (binary) สำหรับบันทึกไฟล์
 * คืนค่า null ถ้าถอดรหัสไม่สำเร็จ
 */
function decodeDataUrlImage($dataUrl) {
    $base64 = stripDataUrlPrefix($dataUrl);
    $binary = base64_decode($base64, true);
    return $binary === false ? null : $binary;
}

/**
 * ทำความสะอาดรหัสพาร์ทให้ใช้เป็นชื่อโฟลเดอร์/ไฟล์ได้อย่างปลอดภัย
 */
function sanitizeForPath($str) {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $str);
}

/**
 * เทียบคลาสที่ AI ตรวจเจอ กับ รหัสพาร์ทที่สแกน โดยตัดอักขระที่ไม่ใช่ตัวอักษร/ตัวเลขออกก่อน
 * (โมเดล AI มักตอบคลาสแบบมีขีด เช่น "JB3Z-17A869-B" ในขณะที่รหัสพาร์ทที่สแกนไม่มีขีด
 *  เช่น "JB3Z17A869B" ถ้าเทียบแบบ string ตรงๆ จะไม่ตรงกันทั้งที่จริงคือพาร์ทเดียวกัน)
 */
function normalizeClassCode($str) {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $str));
}

/**
 * รายชื่อพาร์ทเริ่มต้น (seed) — ใช้ตอน data/parts.json ยังไม่เคยถูกสร้างเลย (เช่น รันระบบนี้
 * เป็นครั้งแรก หรืออัปเกรดจากเวอร์ชันเก่าที่ยัง hardcode รายชื่อพาร์ทไว้ในโค้ด) ชุดนี้คือ
 * รายชื่อพาร์ท + workflow_url เฉพาะ (ถ้ามี) ชุดเดียวกับที่เคย hardcode ไว้ทุกประการ เพื่อไม่ให้
 * ระบบพังหรือพาร์ทหายไปตอนอัปเกรด — หลังจากสร้างไฟล์ data/parts.json ครั้งแรกแล้ว ฟังก์ชันนี้
 * จะไม่ถูกเรียกใช้อีก (แก้ไขพาร์ทได้ผ่านหน้า "Part Master" แทน ไม่ต้องแก้ตรงนี้อีก)
 */
function getDefaultPartsSeed() {
    $workflowOverrides = [
        'JB3Z17A869B' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-3-3',
        'JB3Z17A870B' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-5',
        'N1VZ16038BY' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-2-2',
        // N1WZ16A550AJ ใช้ custom-workflow-2-4 อยู่แล้ว (ตรงกับ Workflow เริ่มต้น) — ใส่ไว้
        // อย่างชัดเจนด้วย เผื่อภายหลังมีการเปลี่ยน ROBOFLOW_WORKFLOW_URL เริ่มต้น จะได้ไม่
        // กระทบพาร์ทนี้โดยไม่ตั้งใจ
        'N1WZ16A550AJ' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-2-4',
        'N1WZ16A550CA' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-2-5',
        'N1WZ16A550CB' => 'https://serverless.roboflow.com/woonchen/workflows/custom-workflow-2',
    ];

    $codes = [
        "JB3Z17A869B", "JB3Z17A870B",
        "N1VZ16038AAN", "N1VZ16038BY", "N1WZ16038DB", "N1WZ16038EA", "N1WZ16038CA",
        "P1VZ16038AC", "P1VZ16038AD", "P1VZ16038BC", "P1VZ16038BD", "P1WZ16038BC", "P1WZ16038EB", "P1WZ16038GB",
        "N1VZ16039BY", "N1WZ16039DC", "N1WZ16039EA",
        "P1VZ16039AC", "P1VZ16039AD", "P1VZ16039BC", "P1VZ16039BD", "P1WZ16039BD", "P1WZ16039EB", "P1WZ16039GB",
        "N1WZ2629038BD", "P1WZ2629038AE", "P1WZ2629038AF", "P1WZ2629038BB", "N1WZ2629039BC",
        "N1WZ16038BB", "N1WZ16039BB",
        "N1WZ16A550CA", "N1WZ16A550AJ", "N1WZ16A550CB",
        "AB3Z17906AA", "AB3Z17906AB", "AB3Z17906C", "AB3Z17906D", "AB3Z17906DA", "AB3Z17906DB",
        "AB3Z17906DE", "AB3Z17906DF", "AB3Z17906DG", "AB3Z17906DH", "AB3Z17906DJ", "AB3Z17906DK", "AB3Z17906DM",
        "AB3Z17906E", "AB3Z17906G",
        "EB3Z17906AC", "EB3Z17906AD", "EB3Z17906AE", "EB3Z17906AF", "EB3Z17906BP",

        // เพิ่มชุดนี้เข้ามาใหม่ (คัดกรองแล้ว: ตัดรายการที่ซ้ำกับด้านบน ตัดรายการซ้ำกันเองใน
        // ชุดที่ได้รับมา 2 รายการ (CN1Z17626BA, CN1Z17626AB) และตัดบรรทัด "c" เดี่ยวๆ ที่หลุด
        // มาปนซึ่งไม่ใช่รหัสพาร์ทจริงออกแล้วตามที่ยืนยัน)
        "CP9Z17626A", "CP9Z17626B",
        "JB3Z1029038AD", "JB3Z1029039AD", "JB3Z16038AD", "JB3Z16039AD",
        "N1WZ16102C", "N1WZ16103C",
        "N1WZ6029038J", "N1WZ6029038M", "N1WZ6029039K", "N1WZ6029039N",
        "CN1Z17626BA", "CN1Z17626AB",
        "N1WZ2620012H", "N1WZ2620013H",
        "P1WZ17906LA", "P1WZ17B968AB",
        "EN1Z17B968BA",
        "N1WZ17754H", "N1WZ17754M",
        "N1WZ17B968D", "N1WZ17B968E", "N1WZ17B968G",
        "JB3Z17B968C", "JB3Z17B968D",
        "JB3Z2627840K", "JB3Z2627841J", "JB3Z2627841M", "JB3Z2627841R", "JB3Z2627841S", "JB3Z2627841T", "JB3Z2627841U",
        "N1VZ16038AAP", "N1VZ16039AL",
        "C1BZ17B968AA", "CN1Z17B968CA",
        "N1WZ2629038CA", "N1WZ2629038CB",
        "P1WZ16038BD", "P1WZ16039BE",
        "N1WZ16039CA",
        "N1VZ16A550D", "N1VZ78016A23B",
        "JB3Z78016A23D",
        "AB3Z2627841V", "AB3Z2627841W", "AB3Z2627841X",
        "N1WZ6027841E", "N1WZ6027841K"
    ];

    $seed = [];
    foreach ($codes as $code) {
        $seed[] = [
            'code' => $code,
            'workflow_url' => isset($workflowOverrides[$code]) ? $workflowOverrides[$code] : null
        ];
    }
    return $seed;
}

/**
 * โหลดรายชื่อพาร์ททั้งหมดจาก data/parts.json — ถ้าไฟล์ยังไม่เคยมี (ครั้งแรกที่รันระบบ)
 * จะสร้างไฟล์พร้อม seed ข้อมูลเริ่มต้นให้อัตโนมัติ (ดู getDefaultPartsSeed() ด้านบน)
 * คืนค่าเป็น array ของ ['code' => ..., 'workflow_url' => ... หรือ null]
 */
function loadPartsList() {
    $data = readModifyWriteJsonFile(PARTS_FILE, function (&$data) {
        if ($data === null) {
            $data = getDefaultPartsSeed();
            return true; // ไฟล์ยังไม่เคยมี/อ่านไม่ออก -> seed ค่าเริ่มต้นแล้วบันทึกลงไฟล์เลย
        }
        return false; // มีอยู่แล้ว ไม่ต้องแก้ไขอะไร แค่โหลดมาอ่าน
    });
    return is_array($data) ? $data : [];
}

/**
 * โหลดรายการรูปตัวอย่าง (Material) ทั้งหมดจาก data/materials.json — ถ้าไฟล์ยังไม่เคยมี
 * จะสร้างไฟล์เปล่า [] ให้อัตโนมัติ (ไม่มี seed ข้อมูลเริ่มต้น ต่างจาก parts.json เพราะรูป
 * ตัวอย่างต้องให้ผู้ใช้อัปโหลดเองทั้งหมด ไม่มีค่าเริ่มต้นที่สมเหตุสมผลให้ seed ไว้ล่วงหน้า)
 * คืนค่าเป็น array ของ ['part_code','image_path_front','image_path_back','image_path_barcode','updated_at']
 */
function loadMaterials() {
    $data = readModifyWriteJsonFile(MATERIALS_FILE, function (&$data) {
        if ($data === null) {
            $data = [];
            return true;
        }
        return false;
    });
    return is_array($data) ? $data : [];
}

/**
 * ค่าตั้งค่าเริ่มต้นของระบบ — ตอนนี้มีแค่ confidence_threshold (เกณฑ์ความมั่นใจขั้นต่ำที่ถือว่า
 * "ผ่าน (OK)" เดิม hardcode ไว้ที่ 0.80 ย้ายมาเก็บเป็นไฟล์แทนเพื่อให้ปรับได้จากหน้าเว็บ)
 */
function getDefaultSettings() {
    return [
        'confidence_threshold' => 0.80
    ];
}

/**
 * โหลดค่าตั้งค่าทั้งหมดจาก data/settings.json — ถ้าไฟล์ยังไม่เคยมี จะสร้างพร้อม seed ค่า
 * เริ่มต้นให้อัตโนมัติ (ดู getDefaultSettings() ด้านบน) ป้องกัน key ขาดหายด้วยการ merge ทับ
 * ค่าเริ่มต้นเสมอ (เผื่ออนาคตเพิ่ม key ใหม่ แต่ไฟล์เก่าที่เคยบันทึกไว้ยังไม่มี key นั้น)
 */
function loadSettings() {
    $data = readModifyWriteJsonFile(SETTINGS_FILE, function (&$data) {
        if (!is_array($data)) {
            $data = getDefaultSettings();
            return true;
        }
        return false;
    });
    return array_merge(getDefaultSettings(), is_array($data) ? $data : []);
}

/**
 * ส่งไฟล์ Excel ล่าสุด (สร้างไว้ล่วงหน้าแล้วโดย regenerateExcelReport() ตอนผู้ใช้กดปุ่ม
 * "บันทึกข้อมูล" — ดู endpoint save_to_excel) ให้ดาวน์โหลด ผ่านลิงก์ตายตัวเดียวกันเสมอ
 * (GET proxy.php?action=download_latest_excel) ไม่ต้องรอสร้างไฟล์สดๆ ตอนกดดาวน์โหลด
 */
function handleDownloadLatestExcel() {
    if (!file_exists(EXCEL_REPORT_FILE)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ยังไม่มีไฟล์ Excel ล่าสุด (ระบบจะสร้างให้อัตโนมัติหลังมีการสแกนชิ้นงานสำเร็จครั้งแรก)";
        return;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="inspection-report-latest.xlsx"');
    header('Content-Length: ' . filesize(EXCEL_REPORT_FILE));
    header('Cache-Control: no-cache, must-revalidate');
    readfile(EXCEL_REPORT_FILE);
}

/**
 * สร้างไฟล์ Excel (.xlsx) พร้อมรูปแนบ (หน้า/หลัง/บาร์โค้ด) จาก records ทั้งหมด แล้วเขียนทับไว้ที่
 * EXCEL_REPORT_FILE เสมอ — พอร์ตมาจากฟังก์ชัน exportFilteredRecordsExcel() ฝั่ง JS (หน้า
 * "ข้อมูลชิ้นงาน") มาทำงานฝั่งเซิร์ฟเวอร์แทน โดยใช้ PHP ZipArchive (มีในเซิร์ฟเวอร์อยู่แล้ว
 * ไม่ต้องเขียน ZIP writer เองแบบฝั่ง JS ที่หลีกเลี่ยงไลบรารีภายนอก)
 *
 * เรียกเมื่อผู้ใช้กดปุ่ม "บันทึกข้อมูล" ในหน้าเว็บเท่านั้น (ดู endpoint save_to_excel) —
 * ผู้ใช้เป็นคนกำหนดเองว่าจะอัปเดตไฟล์เมื่อไหร่ ไม่ได้ถูกเรียกอัตโนมัติจาก flow การสแกน/บันทึก
 * ผลตรวจอีกต่อไป (ของเดิมเคยเรียกอัตโนมัติทุกครั้งที่สแกน แต่ผู้ใช้ต้องการควบคุมจังหวะเอง)
 *
 * ล้มเหลวแบบเงียบๆ ภายใน (log error แต่ไม่ throw ออกไปนอกฟังก์ชัน — คืนค่า false แทน) เพื่อให้
 * endpoint save_to_excel ตอบ error กลับไปให้ผู้ใช้เห็นได้อย่างชัดเจนแทนที่จะทำให้ทั้ง request พัง
 */
function regenerateExcelReport($records) {
    try {
        if (!class_exists('ZipArchive')) {
            error_log('regenerateExcelReport: ไม่มี ZipArchive ข้ามการสร้างไฟล์ Excel อัตโนมัติ');
            return false;
        }

        $dir = dirname(EXCEL_REPORT_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // สร้างลงไฟล์ชั่วคราวก่อนเสมอ แล้วค่อย rename ทับไฟล์จริงตอนจบ (atomic) กันกรณีมีคน
        // กำลังดาวน์โหลดไฟล์เดิมอยู่พอดีตอนที่ระบบกำลังสร้างไฟล์ใหม่ทับ ไม่ให้ได้ไฟล์ที่เขียนค้างครึ่งๆ
        $tmpPath = EXCEL_REPORT_FILE . '.tmp';
        $zip = new ZipArchive();
        if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            error_log('regenerateExcelReport: เปิดไฟล์ zip ชั่วคราวไม่สำเร็จ');
            return false;
        }

        $thumbMaxW = 200;
        $thumbMaxH = 140;
        $emuPerPx = 9525; // มาตรฐาน OOXML: 1 พิกเซล (96dpi) = 9525 EMU

        $headers = ['ID', 'Date Time', 'Part Code', 'Status', 'Detections', 'OK Count', 'NG Count', 'Front', 'Back', 'Barcode'];
        $rows = [$headers];
        foreach ($records as $r) {
            $rows[] = [
                isset($r['id']) ? $r['id'] : '',
                isset($r['datetime']) ? $r['datetime'] : '',
                isset($r['part_code']) ? $r['part_code'] : '',
                isset($r['overall_status']) ? $r['overall_status'] : '',
                isset($r['total_detections']) ? $r['total_detections'] : '',
                isset($r['ok_count']) ? $r['ok_count'] : '',
                isset($r['ng_count']) ? $r['ng_count'] : '',
                '', '', ''
            ];
        }

        // เก็บรูปทั้งหมดที่ต้องฝัง พร้อมตำแหน่งแถว/คอลัมน์ในชีต และขนาดที่ย่อแล้ว (คงสัดส่วนเดิม
        // โดยใช้ getimagesize() อ่านขนาดจริงตรงๆ — ง่ายกว่าฝั่ง JS มากที่ต้องพึ่ง createImageBitmap)
        $imageFields = ['image_path', 'image_path_back', 'image_path_barcode'];
        $images = [];
        $imageId = 1;
        $maxImageHeightPxByRow = [];

        foreach (array_values($records) as $rowIndex => $r) {
            $rowNum = $rowIndex + 1; // แถวข้อมูลแรกในชีตคือ index 1 (index 0 = หัวตาราง)
            foreach ($imageFields as $colOffset => $field) {
                $relPath = isset($r[$field]) ? $r[$field] : null;
                if (!$relPath) {
                    continue;
                }
                $fullPath = __DIR__ . '/' . $relPath;
                if (!file_exists($fullPath)) {
                    continue;
                }

                $dim = @getimagesize($fullPath);
                $naturalW = ($dim && $dim[0] > 0) ? $dim[0] : $thumbMaxW;
                $naturalH = ($dim && $dim[1] > 0) ? $dim[1] : $thumbMaxH;
                $scale = min($thumbMaxW / $naturalW, $thumbMaxH / $naturalH, 1);
                $width = max(1, (int) round($naturalW * $scale));
                $height = max(1, (int) round($naturalH * $scale));

                $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                    $ext = 'jpg';
                }
                if ($ext === 'jpeg') {
                    $ext = 'jpg';
                }

                $images[] = [
                    'id' => $imageId++,
                    'row' => $rowNum,
                    'column' => $colOffset + 7, // 0-based: H=Front(7), I=Back(8), J=Barcode(9)
                    'ext' => $ext,
                    'path' => $fullPath,
                    'width' => $width,
                    'height' => $height
                ];

                if (!isset($maxImageHeightPxByRow[$rowNum]) || $height > $maxImageHeightPxByRow[$rowNum]) {
                    $maxImageHeightPxByRow[$rowNum] = $height;
                }
            }
        }

        $excelColumnName = function ($index) {
            $name = '';
            $value = $index + 1;
            while ($value > 0) {
                $remainder = ($value - 1) % 26;
                $name = chr(65 + $remainder) . $name;
                $value = intdiv($value - 1, 26);
            }
            return $name;
        };

        $pxToPt = 0.75; // ที่ 96dpi: 1px = 0.75pt
        $rowPaddingPx = 12;
        $defaultRowHeightPt = 18;

        $sheetRows = '';
        foreach ($rows as $rowIndex => $row) {
            $heightAttr = '';
            if ($rowIndex > 0) {
                $imgH = isset($maxImageHeightPxByRow[$rowIndex]) ? $maxImageHeightPxByRow[$rowIndex] : 0;
                $rowHeightPt = $imgH > 0 ? (int) ceil(($imgH + $rowPaddingPx) * $pxToPt) : $defaultRowHeightPt;
                $heightAttr = ' ht="' . $rowHeightPt . '" customHeight="1"';
            }
            $cells = '';
            foreach ($row as $colIndex => $value) {
                $cellRef = $excelColumnName($colIndex) . ($rowIndex + 1);
                $cells .= '<c r="' . $cellRef . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</t></is></c>';
            }
            $sheetRows .= '<row r="' . ($rowIndex + 1) . '"' . $heightAttr . '>' . $cells . '</row>';
        }

        // ความกว้างคอลัมน์รูป (H/I/J): ให้พอดีกับกล่องรูปสูงสุด (แปลง px -> หน่วยความกว้าง Excel
        // แบบประมาณค่ามาตรฐานของฟอนต์ Calibri 11 ที่ Excel ใช้คำนวณ — สูตรเดียวกับฝั่ง JS)
        $imageColWidth = round(((($thumbMaxW - 5) / 7)) * 100) / 100;
        $drawingRel = count($images) > 0 ? '<drawing r:id="rId1"/>' : '';
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><cols><col min="1" max="1" width="8" customWidth="1"/><col min="2" max="2" width="20" customWidth="1"/><col min="3" max="3" width="20" customWidth="1"/><col min="4" max="4" width="16" customWidth="1"/><col min="5" max="7" width="13" customWidth="1"/><col min="8" max="10" width="' . $imageColWidth . '" customWidth="1"/></cols><sheetData>' . $sheetRows . '</sheetData>' . $drawingRel . '</worksheet>';

        // ฝังรูปด้วย oneCellAnchor + ขนาดจริง (cx/cy เป็น EMU ที่แปลงจากขนาดรูปที่ย่อไว้แล้ว)
        // เหมือนฝั่ง JS ทุกประการ (กันรูปเพี้ยน/ถูกยืดบีบผิดสัดส่วนแบบที่เคยเจอกับ twoCellAnchor)
        $drawingAnchors = '';
        foreach ($images as $image) {
            $cx = $image['width'] * $emuPerPx;
            $cy = $image['height'] * $emuPerPx;
            $drawingAnchors .= '<xdr:oneCellAnchor><xdr:from><xdr:col>' . $image['column'] . '</xdr:col><xdr:colOff>19050</xdr:colOff><xdr:row>' . $image['row'] . '</xdr:row><xdr:rowOff>19050</xdr:rowOff></xdr:from><xdr:ext cx="' . $cx . '" cy="' . $cy . '"/><xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . $image['id'] . '" name="Inspection Image ' . $image['id'] . '"/><xdr:cNvPicPr/></xdr:nvPicPr><xdr:blipFill><a:blip xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" r:embed="rId' . $image['id'] . '"/><a:stretch xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:fillRect/></a:stretch></xdr:blipFill><xdr:spPr><a:xfrm xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic><xdr:clientData/></xdr:oneCellAnchor>';
        }

        $contentTypes = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Default Extension="jpg" ContentType="image/jpeg"/><Default Extension="png" ContentType="image/png"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . (count($images) > 0 ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '') . '</Types>';

        $zip->addFromString('[Content_Types].xml', $contentTypes);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inspection History" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        if (count($images) > 0) {
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
            $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0" encoding="UTF-8"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . $drawingAnchors . '</xdr:wsDr>');

            $relsXml = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            foreach ($images as $image) {
                $relsXml .= '<Relationship Id="rId' . $image['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image' . $image['id'] . '.' . $image['ext'] . '"/>';
            }
            $relsXml .= '</Relationships>';
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $relsXml);

            foreach ($images as $image) {
                $zip->addFile($image['path'], 'xl/media/image' . $image['id'] . '.' . $image['ext']);
            }
        }

        $zip->close();

        rename($tmpPath, EXCEL_REPORT_FILE);
        return true;
    } catch (Throwable $e) {
        error_log('regenerateExcelReport failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * รายชื่อไฟล์ JSON ทั้งหมดที่ต้องรวมอยู่ในไฟล์สำรอง (key = path ใน ZIP, value = path จริงบนเซิร์ฟเวอร์)
 */
function getBackupDataFiles() {
    return [
        'data/inspections.json' => DATA_FILE,
        'data/parts.json' => PARTS_FILE,
        'data/materials.json' => MATERIALS_FILE,
        'data/settings.json' => SETTINGS_FILE,
    ];
}

/**
 * รายชื่อโฟลเดอร์รูปภาพทั้งหมดที่ต้องรวมอยู่ในไฟล์สำรอง (key = prefix path ใน ZIP,
 * value = path จริงบนเซิร์ฟเวอร์) — ครอบคลุมรูปสแกน/รูปตัวอย่าง/รูปเทรนบาร์โค้ดทั้งหมด
 */
function getBackupDirectories() {
    return [
        'uploads' => UPLOADS_DIR,
        'material' => MATERIAL_DIR,
        'training_data/barcode' => BARCODE_TRAINING_DIR,
    ];
}

/**
 * เพิ่มไฟล์ทั้งหมดในโฟลเดอร์ $realDir (รวมโฟลเดอร์ย่อยทั้งหมด) เข้า ZipArchive โดยใช้ path
 * ใน zip เป็น "$zipPrefix/..." ตรงกับโครงสร้างโฟลเดอร์จริงบนเซิร์ฟเวอร์ทุกประการ เพื่อให้ตอน
 * กู้คืน (handleImportBackup) แตกไฟล์กลับไปวางถูกที่ได้เลยโดยไม่ต้องเดา
 */
function addDirectoryToZip(ZipArchive $zip, $realDir, $zipPrefix) {
    if (!is_dir($realDir)) {
        return;
    }
    $baseReal = realpath($realDir);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }
        $realPath = $fileInfo->getRealPath();
        $relativePath = substr($realPath, strlen($baseReal) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        $zip->addFile($realPath, $zipPrefix . '/' . $relativePath);
    }
}

/**
 * สร้าง+ส่งไฟล์ ZIP สำรองข้อมูลทั้งหมด (JSON ทุกไฟล์ + รูปทุกโฟลเดอร์) ให้ดาวน์โหลด
 * เรียกจาก GET proxy.php?action=export_backup เท่านั้น (ดูจุดเรียกใช้ต้นไฟล์)
 *
 * เหตุผลที่มีฟีเจอร์นี้: hosting บางเจ้า (โดยเฉพาะ free hosting) ล้าง/รีเซ็ตไฟล์เป็นระยะ
 * ไฟล์ข้อมูล/รูปทั้งหมดที่เก็บไว้ในเครื่อง (data/*.json, uploads/, material/, training_data/)
 * จึงมีความเสี่ยงหายได้ ฟีเจอร์นี้ให้ผู้ใช้ดาวน์โหลดสำรองไว้เองเป็นระยะ แล้วกู้คืนได้ภายหลัง
 * ผ่าน handleImportBackup() ด้านล่าง ถ้าข้อมูลบนเซิร์ฟเวอร์หายไปจริงๆ
 */
function handleExportBackup() {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "สร้างไฟล์สำรองไม่ได้: เซิร์ฟเวอร์นี้ไม่ได้เปิดใช้ PHP Zip extension (class ZipArchive ไม่มี) กรุณาติดต่อผู้ดูแล hosting ให้เปิดใช้งาน extension นี้ก่อน";
        return;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'ipack_backup_');
    if ($tmpPath === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "สร้างไฟล์ชั่วคราวสำหรับ backup ไม่สำเร็จ";
        return;
    }

    $zip = new ZipArchive();
    if ($zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "เปิดไฟล์ ZIP สำหรับเขียนไม่สำเร็จ";
        @unlink($tmpPath);
        return;
    }

    // ไฟล์ข้อมูล JSON ทุกไฟล์ (เฉพาะไฟล์ที่มีอยู่จริงเท่านั้น เช่น ระบบที่ยังไม่เคยมีใคร
    // อัปโหลดรูปตัวอย่างเลย จะยังไม่มี data/materials.json ก็ข้ามไปเฉยๆ ไม่ error)
    foreach (getBackupDataFiles() as $zipName => $realPath) {
        if (file_exists($realPath)) {
            $zip->addFile($realPath, $zipName);
        }
    }

    // โฟลเดอร์รูปภาพทั้งหมด (สแกน/ตัวอย่าง/เทรนบาร์โค้ด)
    foreach (getBackupDirectories() as $zipPrefix => $realDir) {
        addDirectoryToZip($zip, $realDir, $zipPrefix);
    }

    // ไฟล์บอกเวลาที่สร้าง backup ไว้ในตัวไฟล์ ZIP เองด้วย เผื่อภายหลังสับสนว่า backup ไฟล์นี้
    // เก่าแค่ไหน (เวลาไทยเสมอ ตาม date_default_timezone_set ต้นไฟล์)
    $zip->addFromString('backup_created_at.txt', date('Y-m-d H:i:s') . ' (Asia/Bangkok)');

    $zip->close();

    $downloadName = 'ipack-backup-' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: no-cache, must-revalidate');
    readfile($tmpPath);
    @unlink($tmpPath);
}

/**
 * กู้คืนข้อมูลทั้งหมดจากไฟล์ ZIP สำรอง (สร้างจาก handleExportBackup ด้านบน) — เขียนทับไฟล์/
 * รูปปัจจุบันที่ชื่อซ้ำกับใน ZIP เท่านั้น (ไฟล์อื่นที่ไม่ได้อยู่ใน ZIP จะไม่ถูกแตะต้อง)
 *
 * ตรวจสอบชื่อไฟล์ในไฟล์ ZIP ทุกรายการก่อนแตกไฟล์เสมอ (กัน path traversal เช่น "../../etc/passwd"
 * ไม่ให้แตะไฟล์นอกโฟลเดอร์แอปนี้ได้ แม้ผู้ใช้จะอัปโหลดไฟล์ ZIP ที่ถูกแก้ไข/ปลอมมา) และอนุญาต
 * เขียนเฉพาะไฟล์ที่อยู่ใต้โฟลเดอร์ data/, uploads/, material/, training_data/barcode/ เท่านั้น
 * (ตรงกับโฟลเดอร์ที่ backup ไว้จริงๆ) กันไฟล์แปลกปลอมอื่นในซิปเขียนทับไฟล์ระบบ (เช่น proxy.php เอง)
 */
function handleImportBackup($input) {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode(['error' => 'กู้คืนข้อมูลไม่ได้: เซิร์ฟเวอร์นี้ไม่ได้เปิดใช้ PHP Zip extension']);
        return;
    }

    $fileDataUrl = isset($input['file']) ? $input['file'] : '';
    if ($fileDataUrl === '') {
        http_response_code(400);
        echo json_encode(['error' => 'ไม่พบไฟล์สำรองที่อัปโหลด']);
        return;
    }

    // ใช้ฟังก์ชันถอดรหัส data URL ตัวเดียวกับที่ใช้ถอดรหัสรูปภาพ (หลักการเดียวกัน: ตัด prefix
    // "data:...;base64," ออกแล้ว base64_decode) ใช้ร่วมกันได้เพราะไม่ได้ผูกกับชนิดไฟล์ใดเป็นพิเศษ
    $binary = decodeDataUrlImage($fileDataUrl);
    if ($binary === null) {
        http_response_code(400);
        echo json_encode(['error' => 'ถอดรหัสไฟล์สำรองไม่สำเร็จ (ไฟล์อาจเสียหาย)']);
        return;
    }

    $tmpPath = tempnam(sys_get_temp_dir(), 'ipack_restore_');
    file_put_contents($tmpPath, $binary);

    $zip = new ZipArchive();
    if ($zip->open($tmpPath) !== true) {
        @unlink($tmpPath);
        http_response_code(400);
        echo json_encode(['error' => 'เปิดไฟล์ ZIP ไม่สำเร็จ (ไฟล์อาจไม่ใช่ไฟล์สำรองที่ถูกต้อง หรือเสียหาย)']);
        return;
    }

    $restoredCount = 0;
    $skippedCount = 0;
    $appRoot = realpath(__DIR__);
    $allowedPrefixes = ['data/', 'uploads/', 'material/', 'training_data/barcode/'];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === false || substr($entryName, -1) === '/') {
            continue; // ข้ามรายการที่เป็นโฟลเดอร์ (ไม่มีเนื้อไฟล์)
        }
        if ($entryName === 'backup_created_at.txt') {
            continue; // ไฟล์ metadata ของ backup เอง ไม่ต้องแตะ
        }

        // กัน path traversal: ต้องไม่มี ".." อยู่ใน path เลย, ไม่มี null byte, และต้องไม่ขึ้นต้น
        // ด้วย "/" (absolute path)
        if (strpos($entryName, '..') !== false || strpos($entryName, "\0") !== false || $entryName[0] === '/') {
            $skippedCount++;
            continue;
        }

        $isAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (strpos($entryName, $prefix) === 0) {
                $isAllowed = true;
                break;
            }
        }
        if (!$isAllowed) {
            $skippedCount++;
            continue;
        }

        $destPath = $appRoot . '/' . $entryName;
        $destDir = dirname($destPath);
        if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
        }

        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $skippedCount++;
            continue;
        }
        file_put_contents($destPath, $content);
        $restoredCount++;
    }

    $zip->close();
    @unlink($tmpPath);

    echo json_encode([
        'success' => true,
        'message' => "กู้คืนข้อมูลเรียบร้อย: {$restoredCount} ไฟล์" . ($skippedCount > 0 ? " (ข้าม {$skippedCount} รายการที่ไม่ปลอดภัย/ไม่เกี่ยวข้อง)" : ""),
        'restored_count' => $restoredCount,
        'skipped_count' => $skippedCount
    ]);
}

/**
 * Helper กลางสำหรับอ่าน-แก้ไข-เขียนไฟล์ JSON ใดๆ แบบปลอดภัยจาก race condition (หลักการ
 * เดียวกับ appendRecordWithLock/updateRecordWithLock: เปิดไฟล์ค้างไว้ + flock(LOCK_EX)
 * ครอบคลุมทั้งช่วงอ่าน-แก้ไข-เขียน) เขียนแยกเป็นฟังก์ชันกลางตรงนี้เพื่อให้ไฟล์ config อื่น
 * ในอนาคต (ไม่ใช่แค่ parts.json) ใช้ pattern เดียวกันได้โดยไม่ต้องเขียนกลไก lock ซ้ำ
 *
 * $filePath  : path ของไฟล์ JSON ที่จะอ่าน/เขียน (สร้างไฟล์+โฟลเดอร์ให้อัตโนมัติถ้ายังไม่มี)
 * $mutatorFn : callback รับ reference ของ $data (array ที่ decode จากไฟล์ หรือ null ถ้าไฟล์
 *              ยังไม่เคยมี/ว่าง/อ่านไม่ออก) ให้แก้ไข $data ได้โดยตรง แล้ว "return true" ถ้า
 *              ต้องการบันทึกกลับไฟล์ หรือ "return false" ถ้าแค่อ่านอย่างเดียวไม่ต้องเขียนทับ
 *
 * คืนค่า $data หลังแก้ไข (ไม่ว่าจะบันทึกจริงหรือไม่) หรือ null ถ้าเปิด/ล็อกไฟล์ไม่สำเร็จ
 */
function readModifyWriteJsonFile($filePath, callable $mutatorFn) {
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fp = fopen($filePath, 'c+');
    if ($fp === false) {
        error_log('readModifyWriteJsonFile: เปิดไฟล์ ' . $filePath . ' ไม่สำเร็จ');
        return null;
    }

    if (!flock($fp, LOCK_EX)) {
        error_log('readModifyWriteJsonFile: ล็อกไฟล์ ' . $filePath . ' ไม่สำเร็จ');
        fclose($fp);
        return null;
    }

    $content = stream_get_contents($fp);
    $data = $content !== '' ? json_decode($content, true) : null;
    if (!is_array($data)) {
        $data = null; // ไฟล์ว่าง/ยังไม่เคยมี/JSON เสีย -> ให้ mutatorFn ตัดสินใจว่าจะ seed อะไร
    }

    $shouldSave = $mutatorFn($data);

    if ($shouldSave) {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);

    return $data;
}

/**
 * โหลดข้อมูล inspections.json (คืน array ว่างถ้ายังไม่มีไฟล์หรืออ่านไม่ได้)
 *
 * หมายเหตุ: ฟังก์ชันนี้ยังคงไว้เผื่อโค้ดส่วนอื่น (เช่นในอนาคต) ต้องการ "อ่านอย่างเดียว"
 * โดยไม่แก้ไขไฟล์ — แต่ถ้าจะ "เขียน" ข้อมูลกลับ ต้องใช้ appendRecordWithLock() ด้านล่าง
 * เท่านั้น ห้ามเรียก loadRecords() แล้วค่อย saveRecords() แยกกัน 2 จังหวะอีก เพราะจะกลับไป
 * มีช่องโหว่ race condition เหมือนเดิม
 */
function loadRecords() {
    if (!file_exists(DATA_FILE)) {
        return [];
    }
    $content = file_get_contents(DATA_FILE);
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * เพิ่ม record ใหม่ลงไฟล์ data/inspections.json แบบปลอดภัยจาก race condition
 *
 * ทำงานโดยเปิดไฟล์ค้างไว้ 1 ครั้ง (โหมด "c+" = สร้างไฟล์ถ้ายังไม่มี + อ่าน/เขียนได้)
 * แล้วขอ exclusive lock (LOCK_EX) ก่อน จากนั้นค่อยอ่านเนื้อหาปัจจุบัน เพื่อการันตีว่า
 * ระหว่างที่เรากำลังอ่าน-คำนวณ id ใหม่-เขียนกลับ จะไม่มี request อื่นมาแก้ไขไฟล์แทรกกลาง
 * คั่นได้เลย (request อื่นที่มาพร้อมกันจะถูกบล็อกรอที่ flock() จนกว่าไฟล์นี้จะถูกปลดล็อก)
 *
 * $buildRecordFn: callback ที่รับ array $records (ข้อมูลปัจจุบันทั้งหมด) แล้วคืนค่า record
 * ใหม่ที่จะ append เข้าไป (ให้ callback เป็นคนคำนวณ id ใหม่จาก $records ที่อ่านได้ล่าสุด
 * ณ ตอนนั้น แทนที่จะคำนวณไว้ก่อนล่วงหน้าข้างนอก เพื่อไม่ให้ id ชนกันเมื่อมีหลาย request)
 *
 * คืนค่า record ที่บันทึกสำเร็จ หรือ null ถ้าล็อก/เขียนไฟล์ไม่สำเร็จ
 */
function appendRecordWithLock(callable $buildRecordFn) {
    $dir = dirname(DATA_FILE);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        error_log('appendRecordWithLock: เปิดไฟล์ ' . DATA_FILE . ' ไม่สำเร็จ');
        return null;
    }

    // ขอ exclusive lock — ถ้ามี request อื่นถือ lock อยู่ (กำลังอ่าน/เขียนไฟล์นี้พอดี)
    // การเรียก flock() แบบไม่ใส่ LOCK_NB จะ "รอ" (block) จนกว่าจะปลดล็อก แล้วค่อยทำงานต่อ
    // ไม่ทำให้ request ไหนข้อมูลหาย เพียงแต่อาจต้องรอคิวเสี้ยววินาทีถ้าชนกันพอดี
    if (!flock($fp, LOCK_EX)) {
        error_log('appendRecordWithLock: ล็อกไฟล์ ' . DATA_FILE . ' ไม่สำเร็จ');
        fclose($fp);
        return null;
    }

    $content = stream_get_contents($fp);
    $records = json_decode($content, true);
    if (!is_array($records)) {
        $records = [];
    }

    $newRecord = $buildRecordFn($records);
    $records[] = $newRecord;

    $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // ต้อง rewind + ftruncate ก่อนเขียนทับเสมอ เพราะไฟล์เดิมอาจยาวกว่าข้อมูลใหม่
    // (ถ้าไม่ truncate แล้วข้อมูลใหม่สั้นกว่าเดิม จะมีเศษ JSON เก่าตกค้างต่อท้าย ทำให้
    //  ไฟล์กลายเป็น JSON ที่ parse ไม่ออกทันที)
    rewind($fp);
    ftruncate($fp, 0);
    $writeOk = fwrite($fp, $json) !== false;
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $writeOk ? $newRecord : null;
}

/**
 * แก้ไข record ที่มีอยู่แล้ว (ค้นหาด้วย id) แบบปลอดภัยจาก race condition เช่นเดียวกับ
 * appendRecordWithLock() ด้านบน — ใช้ตอนต้องการอัปเดตบาง field ของ record เดิมที่บันทึกไปแล้ว
 * (ตอนนี้ใช้กับการบันทึกสาเหตุ NG ย้อนหลัง แต่ออกแบบให้ใช้ทั่วไปได้ด้วย callback)
 *
 * $mutatorFn: callback ที่รับ reference ของ record ที่เจอ ($record) แล้วแก้ไขค่าใน array
 * นั้นได้โดยตรง (pass by reference — ไม่ต้อง return ค่ากลับ)
 *
 * คืนค่า record ที่อัปเดตแล้ว (พร้อมค่าล่าสุดทุก field) หรือ null ถ้าไม่เจอ record ตาม id
 * หรือเปิด/ล็อก/เขียนไฟล์ไม่สำเร็จ
 */
function updateRecordWithLock($id, callable $mutatorFn) {
    if (!file_exists(DATA_FILE)) {
        return null;
    }

    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        error_log('updateRecordWithLock: เปิดไฟล์ ' . DATA_FILE . ' ไม่สำเร็จ');
        return null;
    }

    if (!flock($fp, LOCK_EX)) {
        error_log('updateRecordWithLock: ล็อกไฟล์ ' . DATA_FILE . ' ไม่สำเร็จ');
        fclose($fp);
        return null;
    }

    $content = stream_get_contents($fp);
    $records = json_decode($content, true);
    if (!is_array($records)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    $foundIndex = null;
    foreach ($records as $index => $r) {
        if (isset($r['id']) && (int) $r['id'] === (int) $id) {
            $foundIndex = $index;
            break;
        }
    }

    if ($foundIndex === null) {
        // ไม่เจอ record ตาม id ที่ขอมา — ปลดล็อกแล้วคืน null โดยไม่แก้ไขไฟล์เลย
        flock($fp, LOCK_UN);
        fclose($fp);
        return null;
    }

    $mutatorFn($records[$foundIndex]);

    $json = json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    rewind($fp);
    ftruncate($fp, 0);
    $writeOk = fwrite($fp, $json) !== false;
    fflush($fp);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $writeOk ? $records[$foundIndex] : null;
}
