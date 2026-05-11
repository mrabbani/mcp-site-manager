import { useState, useEffect, useMemo, useCallback, useRef } from '@wordpress/element';
import { DataViews } from '@wordpress/dataviews';
import { Button, Notice, Spinner, __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, sprintf, _n } from '@wordpress/i18n';

const DEFAULT_VIEW = {
    type: 'table',
    page: 1,
    perPage: 25,
    search: '',
    sort: { field: 'ts', direction: 'desc' },
    filters: [],
    fields: [ 'ts', 'user_login', 'ability', 'status', 'error_code', 'duration_ms' ],
    layout: {},
};

const SEARCH_DEBOUNCE_MS = 300;

function formatTs( ts ) {
    if ( ! ts ) return '';
    try {
        const d = new Date( ts.replace( ' ', 'T' ) + 'Z' );
        return d.toLocaleString();
    } catch ( e ) {
        return ts;
    }
}

export default function Log() {
    const [ items, setItems ] = useState( null );
    const [ total, setTotal ] = useState( 0 );
    const [ lastUpdated, setLastUpdated ] = useState( null );
    const [ error, setError ] = useState( null );
    const [ loading, setLoading ] = useState( false );
    const [ view, setView ] = useState( DEFAULT_VIEW );

    // Track in-flight requests so stale responses don't clobber fresh state.
    const requestSeq = useRef( 0 );

    const buildQuery = useCallback( ( v ) => {
        const statusFilter = ( v.filters || [] ).find( ( f ) => f.field === 'status' );
        return {
            page:     v.page || 1,
            per_page: v.perPage || 25,
            orderby:  v.sort?.field || 'ts',
            order:    v.sort?.direction || 'desc',
            search:   v.search || '',
            status:   statusFilter && typeof statusFilter.value === 'string' ? statusFilter.value : '',
        };
    }, [] );

    const fetchPage = useCallback( ( v ) => {
        const reqId = ++requestSeq.current;
        setLoading( true );
        setError( null );
        const path = addQueryArgs( '/mcp-site-manager/v1/log', buildQuery( v ) );
        return apiFetch( { path } )
            .then( ( r ) => {
                if ( reqId !== requestSeq.current ) return; // stale
                setItems( r.items );
                setTotal( r.total );
                setLastUpdated( new Date() );
            } )
            .catch( ( e ) => {
                if ( reqId !== requestSeq.current ) return;
                setError( e.message || String( e ) );
            } )
            .finally( () => {
                if ( reqId === requestSeq.current ) setLoading( false );
            } );
    }, [ buildQuery ] );

    const load = useCallback( () => fetchPage( view ), [ fetchPage, view ] );

    // Refetch on page/perPage/sort/filters changes immediately;
    // debounce search so typing doesn't flood the server.
    useEffect( () => {
        const handle = window.setTimeout( () => {
            fetchPage( view );
        }, view.search ? SEARCH_DEBOUNCE_MS : 0 );
        return () => window.clearTimeout( handle );
    }, [
        fetchPage,
        view.page,
        view.perPage,
        view.search,
        view.sort?.field,
        view.sort?.direction,
        view.filters,
    ] );

    const fields = useMemo( () => [
        {
            id: 'ts',
            label: __( 'Time', 'mcp-site-manager' ),
            enableSorting: true,
            render: ( { item } ) => formatTs( item.ts ),
        },
        {
            id: 'user_login',
            label: __( 'User', 'mcp-site-manager' ),
            enableSorting: true,
            render: ( { item } ) => item.user_login || <em>{ __( '(unknown)', 'mcp-site-manager' ) }</em>,
        },
        {
            id: 'ability',
            label: __( 'Ability', 'mcp-site-manager' ),
            enableSorting: true,
            render: ( { item } ) => <code>{ item.ability }</code>,
        },
        {
            id: 'status',
            label: __( 'Status', 'mcp-site-manager' ),
            enableSorting: true,
            filterBy: { operators: [ 'is' ], isPrimary: true },
            render: ( { item } ) => (
                <span className={ `mcpsm-log-status-badge mcpsm-log-status-badge--${ item.status === 'ok' ? 'ok' : 'error' }` }>
                    { item.status }
                </span>
            ),
            elements: [
                { value: 'ok',    label: __( 'OK', 'mcp-site-manager' ) },
                { value: 'error', label: __( 'Error', 'mcp-site-manager' ) },
            ],
        },
        {
            id: 'error_code',
            label: __( 'Code', 'mcp-site-manager' ),
            enableSorting: true,
        },
        {
            id: 'duration_ms',
            label: __( 'Duration (ms)', 'mcp-site-manager' ),
            type: 'integer',
            enableSorting: true,
            render: ( { item } ) => String( item.duration_ms ),
        },
    ], [] );

    const [ confirmIds, setConfirmIds ] = useState( null );
    const [ deleting, setDeleting ] = useState( false );

    const actions = useMemo( () => [
        {
            id: 'bulk-delete',
            label: __( 'Delete', 'mcp-site-manager' ),
            supportsBulk: true,
            isDestructive: true,
            callback: ( selected ) => {
                const ids = selected.map( ( i ) => i.id );
                if ( ids.length ) setConfirmIds( ids );
            },
        },
    ], [] );

    const onConfirmDelete = useCallback( () => {
        if ( ! confirmIds || ! confirmIds.length ) return;
        setDeleting( true );
        apiFetch( {
            path: '/mcp-site-manager/v1/log/bulk-delete',
            method: 'POST',
            data: { ids: confirmIds },
        } )
            .then( () => load() )
            .catch( ( e ) => setError( e.message || String( e ) ) )
            .finally( () => {
                setDeleting( false );
                setConfirmIds( null );
            } );
    }, [ confirmIds, load ] );

    const paginationInfo = useMemo( () => ( {
        totalItems: total,
        totalPages: Math.max( 1, Math.ceil( total / ( view.perPage || 25 ) ) ),
    } ), [ total, view.perPage ] );

    // Reset to page 1 when the result set changes shape (search/filter/perPage).
    const onChangeView = useCallback( ( next ) => {
        setView( ( prev ) => {
            const shapeChanged =
                prev.search !== next.search ||
                prev.perPage !== next.perPage ||
                JSON.stringify( prev.filters || [] ) !== JSON.stringify( next.filters || [] );
            return shapeChanged ? { ...next, page: 1 } : next;
        } );
    }, [] );

    if ( items === null && !error ) return <Spinner />;
    if ( error && items === null ) {
        return (
            <Notice status="error" isDismissible={ false }>
                { error }
            </Notice>
        );
    }
    if ( !items ) return null;

    return (
        <>
            <div style={ { display: 'flex', gap: '1em', alignItems: 'center', marginBottom: '1em' } }>
                <Button variant="secondary" onClick={ load } disabled={ loading }>
                    { loading ? __( 'Refreshing…', 'mcp-site-manager' ) : __( 'Refresh now', 'mcp-site-manager' ) }
                </Button>
                <span style={ { color: '#646970' } }>
                    { sprintf(
                        /* translators: %1$d: rows on this page, %2$d: total matching rows */
                        __( '%1$d shown / %2$d total', 'mcp-site-manager' ),
                        items.length,
                        total
                    ) }
                    { lastUpdated && (
                        <> · { __( 'Updated', 'mcp-site-manager' ) } { lastUpdated.toLocaleTimeString() }</>
                    ) }
                </span>
            </div>
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>
                    { error }
                </Notice>
            ) }
            <DataViews
                data={ items || [] }
                fields={ fields }
                actions={ actions }
                view={ view }
                onChangeView={ onChangeView }
                paginationInfo={ paginationInfo }
                isLoading={ loading }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
            />
            { confirmIds && (
                <ConfirmDialog
                    isOpen
                    onConfirm={ onConfirmDelete }
                    onCancel={ () => ( deleting ? null : setConfirmIds( null ) ) }
                    confirmButtonText={ __( 'Delete', 'mcp-site-manager' ) }
                    cancelButtonText={ __( 'Cancel', 'mcp-site-manager' ) }
                >
                    { sprintf(
                        /* translators: %d: number of log entries */
                        _n(
                            'Delete %d log entry? This cannot be undone.',
                            'Delete %d log entries? This cannot be undone.',
                            confirmIds.length,
                            'mcp-site-manager'
                        ),
                        confirmIds.length
                    ) }
                </ConfirmDialog>
            ) }
        </>
    );
}
