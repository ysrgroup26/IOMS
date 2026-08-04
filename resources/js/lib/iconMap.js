import {
    ClipboardList, AlertTriangle, ShieldAlert, Siren, Megaphone, Users, Users2,
    HeartPulse, Skull, Stethoscope, Flame, Wrench, HardHat, CheckCircle2,
    Activity, Bell, FileWarning, Truck, Building2,
} from 'lucide-react';

/**
 * String icon names (as stored in kpi_categories.icon, fully
 * admin-configurable) map to real lucide-react components here. This is
 * the ONE place a string name resolves to a component -- Dashboard cards
 * never hardcode "this category code gets this icon"; they just render
 * whatever icon name the database has, falling back to a generic default
 * if an admin picks a name this map doesn't recognize yet.
 */
const ICON_MAP = {
    'clipboard-list': ClipboardList,
    'alert-triangle': AlertTriangle,
    'shield-alert': ShieldAlert,
    siren: Siren,
    megaphone: Megaphone,
    users: Users,
    'users-2': Users2,
    'heart-pulse': HeartPulse,
    skull: Skull,
    stethoscope: Stethoscope,
    flame: Flame,
    wrench: Wrench,
    'hard-hat': HardHat,
    'check-circle': CheckCircle2,
    activity: Activity,
    bell: Bell,
    'file-warning': FileWarning,
    truck: Truck,
    building: Building2,
};

export function resolveIcon(name) {
    return ICON_MAP[name] || ClipboardList;
}

export const AVAILABLE_ICON_NAMES = Object.keys(ICON_MAP);
