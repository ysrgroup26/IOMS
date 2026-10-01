import Photo from '@/Components/public/Photo';
import Reveal from '@/Components/public/Reveal';

/**
 * A visual sequence for the four operational domains below the platform
 * overview. Workshop, field, warehouse and management are different scenes,
 * so they do not share one repeated image-card treatment.
 */
export default function DomainEditorial({ stories }) {
    const [people, field, logistics, management] = stories;

    return (
        <div className="mx-auto mt-14 max-w-6xl">
            <Reveal className="grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <figure className="lg:col-span-7">
                    <Photo
                        name="workshop"
                        alt="A fabrication workshop with an overhead crane, steel sections and a welder at work."
                        ratio="16 / 10"
                        className="rounded-sm"
                        sizes="(min-width: 1024px) 58vw, 100vw"
                    />
                    <figcaption className="mt-2 font-mono text-[10px] uppercase tracking-[0.14em] text-graphite-400">
                        Workshop floor / People at work
                    </figcaption>
                </figure>
                <article className="lg:col-span-5">
                    <h3 className="font-display text-xl font-semibold tracking-tight text-graphite-900 sm:text-2xl">{people.title}</h3>
                    <p className="mt-3 text-[15px] leading-relaxed text-graphite-600">{people.body}</p>
                    <p className="mt-5 border-t border-graphite-200 pt-3 text-sm leading-relaxed text-graphite-500">
                        {people.items.join(' · ')}
                    </p>
                </article>
            </Reveal>

            <Reveal className="my-14 grid gap-4 border-y border-graphite-200 py-7 sm:grid-cols-12 sm:items-baseline sm:gap-8">
                <p className="font-mono text-[10px] uppercase tracking-[0.14em] text-graphite-400 sm:col-span-2">Field / My Work</p>
                <h3 className="font-display text-lg font-semibold tracking-tight text-graphite-900 sm:col-span-4">{field.title}</h3>
                <div className="sm:col-span-6">
                    <p className="text-[15px] leading-relaxed text-graphite-600">{field.body}</p>
                    <p className="mt-3 text-sm leading-relaxed text-graphite-500">{field.items.join(' · ')}</p>
                </div>
            </Reveal>

            <Reveal className="relative isolate overflow-hidden bg-navy-900 text-white">
                <Photo
                    name="warehouse"
                    alt="A warehouse aisle lined with racked pallets, with a forklift working at the far end."
                    ratio="16 / 6"
                    className="absolute inset-0 h-full w-full"
                    imgClassName="object-center"
                    sizes="(min-width: 1024px) 100vw, 100vw"
                />
                <div className="absolute inset-0 bg-gradient-to-r from-navy-950/95 via-navy-950/75 to-navy-950/10" />
                <article className="relative flex min-h-[300px] flex-col justify-end px-6 py-8 sm:min-h-[380px] sm:max-w-2xl sm:px-10 sm:py-10">
                    <p className="font-mono text-[10px] uppercase tracking-[0.14em] text-steel-300">Warehouse / Materials</p>
                    <h3 className="mt-3 font-display text-2xl font-semibold tracking-tight text-white sm:text-3xl">{logistics.title}</h3>
                    <p className="mt-3 max-w-xl text-[15px] leading-relaxed text-navy-100">{logistics.body}</p>
                    <p className="mt-4 text-sm leading-relaxed text-navy-200">{logistics.items.join(' · ')}</p>
                </article>
            </Reveal>

            <Reveal className="mt-14 grid items-center gap-8 lg:grid-cols-12 lg:gap-12">
                <article className="lg:col-span-5 lg:order-1">
                    <p className="font-mono text-[10px] uppercase tracking-[0.14em] text-graphite-400">Management / Shared view</p>
                    <h3 className="mt-3 font-display text-xl font-semibold tracking-tight text-graphite-900 sm:text-2xl">{management.title}</h3>
                    <p className="mt-3 text-[15px] leading-relaxed text-graphite-600">{management.body}</p>
                    <p className="mt-5 border-t border-graphite-200 pt-3 text-sm leading-relaxed text-graphite-500">
                        {management.items.join(' · ')}
                    </p>
                </article>
                <figure className="lg:col-span-7 lg:order-2">
                    <Photo
                        name="management"
                        alt="A meeting room overlooking a refinery, with the IOMS dashboard on the wall screen."
                        ratio="16 / 9"
                        className="rounded-sm"
                        sizes="(min-width: 1024px) 58vw, 100vw"
                    />
                    <figcaption className="mt-2 text-right font-mono text-[10px] uppercase tracking-[0.14em] text-graphite-400">
                        Management view / The same operation
                    </figcaption>
                </figure>
            </Reveal>
        </div>
    );
}
