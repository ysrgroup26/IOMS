/**
 * v1.11.7 (Production Readiness Follow-Up, Part 4 -- Bahasa Indonesia
 * Standardization). A single, consistent UI-facing terminology
 * dictionary for Indonesian, built as the "terminology map" the
 * directive explicitly asked to be created BEFORE any page gets edited
 * -- every subsequent translation in this app should pull from here
 * rather than inventing its own phrasing for the same concept, which is
 * exactly how the same term ends up worded three different ways across
 * three pages.
 *
 * Scope this pass: navigation labels (`workspaces.js`) and the five
 * priority department Overview pages (headers, KPI labels, section
 * titles) -- the highest-visibility, most-consistently-seen surfaces.
 * Individual module CRUD forms/dialogs/tables/validation messages are
 * NOT translated yet (a much larger surface, dozens of files) -- adding
 * their strings to this same dictionary and swapping them in is the
 * defined follow-up, not a restart.
 *
 * Deliberately a plain lookup object, not a full i18n framework (no
 * pluralization/interpolation engine) -- this app has exactly one
 * target language today. `t(key)` below is intentionally the same shape
 * a real i18n library's translate function would have
 * (`t('nav.employees')` -> string), so swapping in a library later (for
 * a genuine multi-locale need) means changing this file's internals,
 * not every call site.
 *
 * Rules applied consistently throughout:
 * - Established technical/industry terms are kept AS-IS, never forced
 *   into an unnatural Indonesian translation: HSE, PPE/APD, JSA, HIRADC,
 *   PTW, LOTO, CAPA, NCR, Gas Test, Man-Hour, Man-Power, Work Order,
 *   Asset, Overview -> "Ringkasan" (a real, natural translation, unlike
 *   the technical acronyms above).
 * - Internal identifiers (route names, model names, database fields,
 *   enum values, API identifiers) are NEVER touched by this file --
 *   only user-facing text.
 */
export const ID = {
    // Global / navigation chrome
    dashboard: 'Dasbor',
    overview: 'Ringkasan',
    department: 'Departemen',
    settings: 'Pengaturan',
    reports: 'Laporan',
    notifications: 'Notifikasi',
    search: 'Cari',
    logout: 'Keluar',

    // Common actions
    save: 'Simpan',
    cancel: 'Batal',
    edit: 'Ubah',
    delete: 'Hapus',
    add: 'Tambah',
    create: 'Buat',
    view: 'Lihat',
    viewAll: 'Lihat Semua',
    filter: 'Filter',
    export: 'Ekspor',
    print: 'Cetak',
    submit: 'Ajukan',
    approve: 'Setujui',
    reject: 'Tolak',
    close: 'Tutup',
    back: 'Kembali',

    // Common fields
    company: 'Perusahaan',
    departmentField: 'Departemen',
    status: 'Status',
    date: 'Tanggal',
    dueDate: 'Tanggal Jatuh Tempo',
    notes: 'Catatan',
    remarks: 'Keterangan',
    location: 'Lokasi',
    quantity: 'Jumlah',
    total: 'Total',
    activity: 'Aktivitas',
    recentActivity: 'Aktivitas Terbaru',
    actionRequired: 'Tindakan Diperlukan',
    calendar: 'Kalender',

    // HSE
    hse: 'HSE',
    ppe: 'APD',
    ppeFull: 'Alat Pelindung Diri (APD)',
    incident: 'Insiden',
    incidentManagement: 'Manajemen Insiden',
    safetyObservation: 'Observasi Keselamatan',
    inspection: 'Inspeksi',
    correctiveAction: 'Tindakan Perbaikan',
    capa: 'CAPA',
    ncr: 'NCR',
    permitToWork: 'Izin Kerja',
    ptw: 'PTW',
    gasTest: 'Uji Gas',
    loto: 'LOTO',
    jsa: 'JSA',
    hiradc: 'HIRADC',
    hazardCategory: 'Kategori Bahaya',
    safetyEquipment: 'Peralatan Keselamatan',
    p3k: 'P3K',
    wasteManagement: 'Pengelolaan Limbah',
    contractorManagement: 'Manajemen Kontraktor',
    visitorManagement: 'Manajemen Pengunjung',
    documentControl: 'Kontrol Dokumen',
    masterData: 'Data Master',
    safetyManagement: 'Manajemen Keselamatan',
    permitWorkSafety: 'Izin & Keselamatan Kerja',
    peopleAndPpe: 'Personel & APD',
    hseControl: 'Kontrol HSE',
    openIncidents: 'Insiden Terbuka',
    criticalIncidents: 'Insiden Kritis',
    openObservations: 'Observasi Terbuka',
    openCapa: 'CAPA Terbuka',
    activePtw: 'Izin Kerja Aktif',
    ppeAlerts: 'Peringatan APD',
    safetyPerformance: 'Performa Keselamatan',
    manHour: 'Man-Hour',
    manHourToday: 'Man-Hour Hari Ini',
    manHourThisMonth: 'Man-Hour Bulan Ini',
    manHourYtd: 'Man-Hour Tahun Berjalan',

    // HRD
    hrd: 'HRD',
    employees: 'Karyawan',
    activeEmployees: 'Karyawan Aktif',
    onShiftToday: 'Bertugas Hari Ini',
    onLeaveToday: 'Cuti Hari Ini',
    leave: 'Cuti',
    leaveRequests: 'Pengajuan Cuti',
    pendingApprovals: 'Menunggu Persetujuan',
    contractExpiring: 'Kontrak Akan Berakhir',
    certificationExpiring: 'Sertifikasi Akan Berakhir',
    manPower: 'Man-Power',
    workforceStatus: 'Status Tenaga Kerja',
    attentionRequired: 'Perlu Perhatian',
    shiftRoster: 'Shift & Roster',
    competency: 'Kompetensi',

    // Project Management
    project: 'Proyek',
    projects: 'Proyek',
    projectManagement: 'Manajemen Proyek',
    activeProjects: 'Proyek Aktif',
    delayedProjects: 'Proyek Terlambat',
    milestone: 'Milestone',
    milestones: 'Milestone',
    milestoneCompletion: 'Penyelesaian Milestone',
    projectPortfolio: 'Portofolio Proyek',
    manager: 'Manajer',
    progress: 'Progres',
    nextMilestone: 'Milestone Berikutnya',
    dailyReport: 'Laporan Harian',
    task: 'Tugas',
    tasks: 'Tugas',

    // Logistics / PPIC
    logistics: 'Logistik',
    ppic: 'PPIC',
    procurement: 'Pengadaan',
    materialRequest: 'Permintaan Material',
    purchaseRequisition: 'Pengajuan Pembelian',
    purchaseOrder: 'Pesanan Pembelian',
    goodsReceipt: 'Penerimaan Barang',
    materialFlow: 'Alur Material',
    pendingMaterialRequests: 'Permintaan Material Tertunda',
    waitingApprovals: 'Menunggu Persetujuan',
    lowStock: 'Stok Menipis',

    // Warehouse
    warehouse: 'Gudang',
    inventory: 'Inventaris',
    inventoryHealth: 'Kesehatan Inventaris',
    stock: 'Stok',
    stockMovement: 'Pergerakan Stok',
    item: 'Barang',
    category: 'Kategori',
    currentStock: 'Stok Saat Ini',
    minReorderLevel: 'Batas Minimum',
    healthy: 'Sehat',
    critical: 'Kritis',
    outOfStock: 'Stok Habis',
    receiving: 'Penerimaan',
    issuing: 'Pengeluaran',

    // Empty / status states
    noDataFound: 'Tidak ada data ditemukan',
    nothingHere: 'Belum ada data',
    loading: 'Memuat...',
    notAvailable: 'Tidak tersedia',
};

/**
 * Looks up a dictionary key; returns the key itself (not a crash) if
 * missing, so a not-yet-translated string degrades to something visible
 * and greppable rather than blank. Prefer importing `ID` directly for
 * simple cases -- this exists for call sites that want a safe fallback.
 */
export function t(key) {
    return ID[key] ?? key;
}
