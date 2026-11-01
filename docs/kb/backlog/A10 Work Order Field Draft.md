---
status: DRAFT FOR THE OWNER'S REVIEW (A10). Not a final format. Nothing here is implemented.
date: 2026-11-01
---

# A10 — Work Order: complete field draft for review

**Base:** the six sections the owner set:

1. Requester Information
2. Job Description
3. Resources & Materials
4. Safety & Compliance
5. Approval Sign-off
6. Close-out & Inspection

Also fixed by the owner:

- bilingual labels; common technical terms stay as they are;
- an urgency choice;
- PTW Requirement Yes/No with permit types;
- Approval Sign-off and Close-out configurable per company;
- a PTW is not required before approval, and the reminder appears at Execution;
- quota W1 (Starter none, Professional 200 per month, Business 500 per month).

**Every field below is a proposal.** Columns:

- **Req.** — proposed required (✔) or optional (○);
- **Source** — where the value comes from without retyping;
- **?** — a question for the owner.

Labels are shown as *English / Indonesian*, following the product's language rule: the field label
is English, and the Indonesian appears on the printed bilingual document.

## Header (printed on every page)

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Work Order No. / No. Work Order | ✔ | `WO-{YEAR}-{SEQ}` from Numbering (per company, ADR 063: reserved on save) | Prefix "WO" acceptable on the document, while the feature is called "Work Order"? |
| Status | ✔ | Draft → Submitted → In Approval → Authorized → In Execution → Completed → Closed; Rejected; Cancelled | Is "On Hold" needed? |
| Urgency / Tingkat Urgensi | ✔ | Low / Normal / High / Emergency | Do Emergency WOs skip any stage, or only notify louder? |
| Revision / Revisi | ○ | Incremented when an authorized WO is amended | Are amendments after authorization allowed at all? |

## 1. Requester Information / Informasi Pemohon

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Requested by / Diajukan oleh | ✔ | The signed-in user (never typed) | |
| Position / Jabatan | ✔ | The requester's employee position, snapshotted (ADR 068) | |
| Department / Departemen | ✔ | The requester's department, editable to the requesting department | |
| Operating Unit / Unit Operasi | ✔ | Company | |
| Contact / Kontak | ○ | Phone from the employee record | |
| Request date / Tanggal pengajuan | ✔ | Now | |
| Project / Proyek | ○ | Project Master | Required for project work? |
| Cost centre / Pusat biaya | ○ | Free text or a list | Needed? |

## 2. Job Description / Uraian Pekerjaan

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Title / Judul pekerjaan | ✔ | Text, 120 characters | |
| Scope of work / Lingkup pekerjaan | ✔ | Long text | |
| Work category / Kategori | ✔ | Company list (e.g. Repair, Fabrication, Maintenance, Installation, Inspection) | Company-defined list? |
| Location / area / Lokasi kerja | ✔ | Text plus optional area list | |
| Equipment / asset / Peralatan | ○ | Asset or Equipment Register | Which register: Asset, HSE Equipment, or both? |
| Planned start / Rencana mulai | ✔ | Date and time | |
| Planned finish / Rencana selesai | ✔ | Date and time | |
| Estimated duration / Estimasi durasi | ○ | Computed from start and finish, editable in man-hours | Man-hours or calendar time? |
| PTW required / Memerlukan PTW | ✔ | Yes / No | |
| Permit types / Jenis izin kerja | ✔ if Yes | Multi-select of PTW types | |
| Attachments / Lampiran | ○ | Drawings, photos | Size limit (storage is deferred)? |

## 3. Resources & Materials / Sumber Daya & Material

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Person in charge / Penanggung jawab (PIC) | ✔ | Employee | |
| Workforce / Tenaga kerja | ○ | Employees (multi), and/or contractor plus headcount | Contractor master or free text? |
| Contractor / Kontraktor | ○ | Text or a contractor list | |
| Tools & equipment / Alat kerja | ○ | Rows: item, quantity | |
| Materials / Material | ○ | Rows: item, quantity, unit; or **link to a Material Request** (no retyping) | Create an MR from the WO? |
| Estimated cost / Estimasi biaya | ○ | Number (Rp) | Shown on the document? |

## 4. Safety & Compliance / Keselamatan & Kepatuhan

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Hazards / Bahaya | ✔ if PTW = Yes | Hazard categories (HSE master) | Required even without PTW? |
| Controls / Pengendalian | ○ | Text | |
| Required PPE / APD wajib | ○ | PPE catalogue (same picker as PTW) | |
| JSA / HIRADC reference | ○ | Link to an existing JSA or HIRADC | |
| Linked PTW(s) / PTW terkait | — | Filled when a PTW is created from or linked to this WO after Authorization | |
| Isolation (LOTO) | ○ | Link to LOTO records | |
| Environmental note / Catatan lingkungan | ○ | Text (waste generated, etc.) | Needed? |

## 5. Approval Sign-off / Persetujuan

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Stages | ✔ | The company's **Approval Flow** for `work_order` (this release's builder: role, position or person; versioned) | Same separation-of-duties rules as PTW? |
| Per signature | — | Name, position title at the time, decision, time, comment (approval stamp, ADR 065) | |
| Authorized at / Disahkan pada | — | When the last stage approves; status → Authorized | |

## 6. Close-out & Inspection / Penutupan & Inspeksi

Configurable per company: **Full** (fields below), **Link to QC document** (one reference), or **Off**.

| Field | Req. | Source / behaviour | ? |
|---|---|---|---|
| Actual start / Mulai aktual | ✔ | Date and time (set on "Start Execution") | |
| Actual finish / Selesai aktual | ✔ | Date and time | |
| Work done / Hasil pekerjaan | ✔ | Text | |
| Materials used / Material terpakai | ○ | Rows (prefilled from section 3) | Feed stock movements? |
| Inspection result / Hasil inspeksi | ✔ (Full) | Accepted / Accepted with notes / Rejected | |
| Inspector / Pemeriksa | ✔ (Full) | Employee or user; never the requester (SoD, as in PTW?) | |
| Inspection date / Tanggal inspeksi | ✔ (Full) | Date | |
| Punch list / Catatan perbaikan | ○ | Rows; a Rejected result returns the WO to In Execution | |
| QC document reference | ✔ (QC mode) | Link | Which QC document type? |
| Photos / Foto | ○ | Attachments | |
| Closed by / Ditutup oleh | — | User and time | |

## Behaviour already decided (for completeness)

- Execution reminder (Indonesian): "Jangan lupa menyiapkan Permit to Work (PTW) apabila pekerjaan ini
  memerlukannya."
- Starter has no Work Order. Professional is limited to 200 and Business to 500 per month, as a separate
  quota at PTW pricing.
- A **legacy** `work_orders` table exists from the retired Maintenance workspace (`wo_number`,
  `asset_id`, `technician_id`, …). **Question:** extend it, or start a new table and leave the old one as
  is? Recommendation: a new table, because the shape and lifecycle differ.

## Decisions requested

1. Approve, change or remove each field (especially the **?** column).
2. Workflow statuses: is the proposed list right?
3. Does the Work Order Approval Flow use the same separation-of-duties rules as PTW? Should the
   Close-out inspector be barred from being the requester?
4. Use a new table or the legacy `work_orders` table?
