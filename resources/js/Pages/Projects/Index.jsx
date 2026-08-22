import { Head, router, Link } from '@inertiajs/react';
import { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Card, CardContent } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Badge } from '@/Components/ui/badge';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/Components/ui/select';
import { Search, Plus, ChevronLeft, ChevronRight, MapPin, Users } from 'lucide-react';

const STATUS_VARIANT = {
    planned: 'secondary',
    ongoing: 'success',
    completed: 'outline',
    cancelled: 'destructive',
};

export default function ProjectsIndex({ projects, companies, filters, can }) {
    const [search, setSearch] = useState(filters.search || '');

    function applyFilters(overrides = {}) {
        router.get(route('projects.index'), {
            search, company_id: filters.company_id, status: filters.status, ...overrides,
        }, { preserveState: true, replace: true });
    }

    return (
        <AuthenticatedLayout>
            <Head title="Projects" />

            <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight text-graphite-900">Projects</h1>
                    <p className="mt-1 text-sm text-graphite-500">{projects.total} projects total</p>
                </div>
                {can.manage && (
                    <Button asChild>
                        <Link href={route('projects.create')}><Plus className="h-4 w-4" /> Add Project</Link>
                    </Button>
                )}
            </div>

            <Card>
                <CardContent className="flex flex-wrap gap-2 p-4">
                    <div className="relative flex-1 min-w-[200px]">
                        <Search className="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-graphite-400" />
                        <Input
                            className="pl-8"
                            placeholder="Search project or location..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && applyFilters()}
                        />
                    </div>
                    <Select
                        value={filters.company_id ? String(filters.company_id) : 'all'}
                        onValueChange={(v) => applyFilters({ company_id: v === 'all' ? null : v })}
                    >
                        <SelectTrigger className="w-40"><SelectValue placeholder="Company" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Companies</SelectItem>
                            {companies.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}
                    >
                        <SelectTrigger className="w-40"><SelectValue placeholder="Status" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Statuses</SelectItem>
                            <SelectItem value="planned">Planned</SelectItem>
                            <SelectItem value="ongoing">Ongoing</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="cancelled">Cancelled</SelectItem>
                        </SelectContent>
                    </Select>
                    <Button variant="secondary" onClick={() => applyFilters()}>Search</Button>
                </CardContent>
            </Card>

            <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {projects.data.length === 0 ? (
                    <Card className="col-span-full">
                        <CardContent className="py-10 text-center text-graphite-400">No projects found.</CardContent>
                    </Card>
                ) : projects.data.map((project) => (
                    <Link key={project.id} href={route('projects.show', project.id)}>
                        <Card className="h-full transition-shadow hover:shadow-card-hover">
                            <CardContent className="p-3.5">
                                <div className="mb-2 flex items-start justify-between gap-2">
                                    <h3 className="text-[13px] font-semibold text-graphite-900">{project.name}</h3>
                                    <Badge variant={STATUS_VARIANT[project.status]} className="shrink-0 capitalize">{project.status}</Badge>
                                </div>
                                {project.vessel_name && (
                                    <p className="mb-3 flex items-center gap-1.5 text-sm text-graphite-500">
                                        <MapPin className="h-3.5 w-3.5" /> {project.vessel_name}
                                    </p>
                                )}
                                <div className="flex items-center justify-between text-xs text-graphite-400">
                                    <span>{project.company?.name}</span>
                                    <span className="flex items-center gap-1"><Users className="h-3.5 w-3.5" /> {project.manpower_count} manpower</span>
                                </div>
                            </CardContent>
                        </Card>
                    </Link>
                ))}
            </div>

            {projects.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-graphite-500">
                    <span>Page {projects.current_page} of {projects.last_page}</span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline" size="sm"
                            disabled={!projects.prev_page_url}
                            onClick={() => router.get(projects.prev_page_url, {}, { preserveState: true })}
                        >
                            <ChevronLeft className="h-4 w-4" /> Prev
                        </Button>
                        <Button
                            variant="outline" size="sm"
                            disabled={!projects.next_page_url}
                            onClick={() => router.get(projects.next_page_url, {}, { preserveState: true })}
                        >
                            Next <ChevronRight className="h-4 w-4" />
                        </Button>
                    </div>
                </div>
            )}
        </AuthenticatedLayout>
    );
}
