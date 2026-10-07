/**
 * v2.106.0 -- THE PRODUCT'S OWN NAME FOR EACH DOCUMENT TYPE.
 *
 * Settings' Numbering, Approval Flow, Documents and Import/Export Mapping
 * tabs all let an administrator choose a module, and every one of them used
 * to render the stored key with its underscores swapped for spaces --
 * "material request", "permit to work", "hse inspection". Several then
 * applied the CSS `capitalize` rule on top, which capitalises the first
 * letter of every word and so produced "Hse Inspection", "Jsa" and "Loto".
 *
 * No mechanical rule gets these right. "Permit to Work" has a lower-case
 * "to"; "HSE Inspection" and "JSA" are initialisms. So the names are
 * written down and looked up.
 *
 * MIRRORED FROM `config/naming.php`, deliberately rather than sent as a
 * prop: it is a fixed vocabulary that changes when a module is added, not
 * per request, and shipping it on every page load to avoid a mirrored list
 * would be the worse trade. `ProductTerminologyTest` compares the two and
 * fails if they drift, or if a module key gains no label on either side.
 */
export const MODULE_LABELS = {
    material_request: 'Material Request',
    incident: 'Incident',
    incident_investigation: 'Incident Investigation',
    leave_request: 'Leave Request',
    employee_case: 'Employee Case',
    goods_receipt: 'Goods Receipt',
    ppe_replacement_request: 'PPE Replacement Request',
    task: 'Task',
    milestone: 'Milestone',
    safety_observation: 'Safety Observation',
    risk_assessment: 'Risk Assessment',
    jsa: 'JSA',
    permit_to_work: 'Permit to Work',
    tbm: 'TBM',
    hse_inspection: 'HSE Inspection',
    loto: 'LOTO',
    waste_record: 'Waste Record',
    vendor: 'Vendor',
    purchase_requisition: 'Purchase Requisition',
    rfq: 'RFQ',
    purchase_order: 'Purchase Order',
    item: 'Item',
    stock_movement: 'Stock Movement',
    asset: 'Asset',
    maintenance_request: 'Maintenance Request',
    work_order: 'Work Order',
    inspection_request: 'Inspection Request',
    ncr: 'NCR',
    contractor: 'Contractor',
    visitor: 'Visitor',
    controlled_document: 'Controlled Document',
    handover_record: 'Handover Record',
    regulation_register: 'Regulation Register',
    employees: 'Employees',
};

/**
 * The product's name for a module key, or a readable fallback for a key
 * nobody has named yet. Never returns an underscore.
 */
export function moduleLabel(key) {
    if (key === null || key === undefined || key === '') return '';

    return MODULE_LABELS[key] ?? humanizeTokenFallback(String(key));
}

/**
 * Deliberately local and deliberately small: `humanizeToken` lives in its
 * own module and importing it here would make every page that wants a
 * module label pull in the status vocabulary too.
 */
function humanizeTokenFallback(value) {
    return value
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

export default moduleLabel;
