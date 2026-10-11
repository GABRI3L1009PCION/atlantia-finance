import { Head } from '@inertiajs/react';
import {
    PageHeader,
    Shell,
    secondaryButton,
} from '@/components/office-admin/kit';
import { Card, Table, money, toneBadge } from '@/components/supervisor/kit';
import type { TaxContext } from '@/components/tax/kit';

type Book = {
    key: string;
    company: string;
    month: string;
    status: string;
    sales: number | null;
    purchases: number | null;
    sales_vat_cents: number | null;
    purchase_vat_cents: number | null;
    payable_cents: number | null;
    failing: string[];
    can_close: boolean;
    error: string | null;
    destination: string;
};

export default function TaxVatBooks({
    books,
}: {
    context: TaxContext;
    books: Book[];
}) {
    return (
        <>
            <Head title="Libros IVA" />
            <Shell>
                <PageHeader
                    title="Libros IVA"
                    subtitle="Construidos desde ventas, compras y FEL reales; los errores se corrigen en el documento de origen"
                />
                <Card title={`${books.length} libro(s) de los últimos meses`}>
                    <Table
                        headers={[
                            'Empresa',
                            'Mes',
                            'Ventas',
                            'Compras',
                            'IVA débito',
                            'IVA crédito',
                            'A pagar',
                            'Validaciones',
                            'Estado',
                            '',
                        ]}
                        empty={
                            books.length === 0 &&
                            'Ninguna empresa asignada lleva IVA.'
                        }
                    >
                        {books.map((book) => (
                            <tr key={book.key} className="align-top">
                                <td className="px-2 py-2 font-bold">
                                    {book.company}
                                </td>
                                <td className="px-2 py-2 capitalize">
                                    {book.month}
                                </td>
                                <td className="px-2 py-2">
                                    {book.sales ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {book.purchases ?? '—'}
                                </td>
                                <td className="px-2 py-2">
                                    {book.sales_vat_cents === null
                                        ? 'No disponible'
                                        : money(book.sales_vat_cents)}
                                </td>
                                <td className="px-2 py-2">
                                    {book.purchase_vat_cents === null
                                        ? 'No disponible'
                                        : money(book.purchase_vat_cents)}
                                </td>
                                <td className="px-2 py-2 font-bold">
                                    {book.payable_cents === null
                                        ? 'No disponible'
                                        : money(book.payable_cents)}
                                </td>
                                <td className="px-2 py-2 text-xs">
                                    {book.error ? (
                                        <span className="text-violet-700">
                                            {book.error}
                                        </span>
                                    ) : book.failing.length === 0 ? (
                                        <span className="text-emerald-700">
                                            Sin errores
                                        </span>
                                    ) : (
                                        <span className="text-red-700">
                                            {book.failing.join(' · ')}
                                        </span>
                                    )}
                                </td>
                                <td className="px-2 py-2">
                                    <span
                                        className={toneBadge(
                                            book.status === 'closed'
                                                ? 'green'
                                                : book.status === 'error'
                                                  ? 'violet'
                                                  : book.can_close
                                                    ? 'blue'
                                                    : 'amber',
                                        )}
                                    >
                                        {book.status === 'closed'
                                            ? 'Cerrado'
                                            : book.status === 'error'
                                              ? 'No disponible'
                                              : book.can_close
                                                ? 'Listo para cerrar'
                                                : 'Pendiente'}
                                    </span>
                                </td>
                                <td className="px-2 py-2">
                                    <a
                                        href={book.destination}
                                        className={secondaryButton}
                                    >
                                        Abrir libro
                                    </a>
                                </td>
                            </tr>
                        ))}
                    </Table>
                </Card>
            </Shell>
        </>
    );
}
