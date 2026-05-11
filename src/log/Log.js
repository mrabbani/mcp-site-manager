import { useState, useEffect, useMemo, useCallback } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { Button, Notice, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const PER_PAGE = 200;

const DEFAULT_VIEW = {
    type: 'table',
    page: 1,
    perPage: 25,
    search: '',
    sort: { field: 'ts', direction: 'desc' },
    fields: [ 'ts', 'user_login', 'ability', 'status', 'error_code', 'duration_ms' ],
    layout: {},
};

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

    const load = useCallback( () => {
        setLoading( true );
        setError( null );
        apiFetch( { path: `/mcp-site-manager/v1/log?per_page=${ PER_PAGE }&page=1` } )
            .then( ( r ) => {
                setItems( r.items );
                setTotal( r.total );
                setLastUpdated( new Date() );
            } )
            .catch( ( e ) => setError( e.message || String( e ) ) )
            .finally( () => setLoading( false ) );
    }, [] );

    useEffect( () => {
        load();
    }, [ load ] );

    const fields = useMemo( () => [
        {
            id: 'ts',
            label: __( 'Time', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => formatTs( item.ts ),
        },
        {
            id: 'user_login',
            label: __( 'User', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => item.user_login || <em>{ __( '(unknown)', 'mcp-site-manager' ) }</em>,
        },
        {
            id: 'ability',
            label: __( 'Ability', 'mcp-site-manager' ),
            enableGlobalSearch: true,
            render: ( { item } ) => <code>{ item.ability }</code>,
        },
        {
            id: 'status',
            label: __( 'Status', 'mcp-site-manager' ),
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
            enableGlobalSearch: true,
        },
        {
            id: 'duration_ms',
            label: __( 'Duration (ms)', 'mcp-site-manager' ),
            type: 'integer',
            render: ( { item } ) => String( item.duration_ms ),
        },
    ], [] );

    const bulkDelete = useCallback( ( selected ) => {
        const ids = selected.map( ( i ) => i.id );
        if ( ids.length === 0 ) return Promise.resolve();
        if ( ! window.confirm(
            __( 'Delete the selected log entries? This cannot be undone.', 'mcp-site-manager' )
        ) ) {
            return Promise.resolve();
        }
        return apiFetch( {
            path: '/mcp-site-manager/v1/log/bulk-delete',
            method: 'POST',
            data: { ids },
        } )
            .then( () => load() )
            .catch( ( e ) => setError( e.message || String( e ) ) );
    }, [ load ] );

    const actions = useMemo( () => [
        {
            id: 'bulk-delete',
            label: __( 'Delete', 'mcp-site-manager' ),
            supportsBulk: true,
            isDestructive: true,
            callback: bulkDelete,
        },
    ], [ bulkDelete ] );

    const { data, paginationInfo } = useMemo(
        () =>
            items
                ? filterSortAndPaginate( items, view, fields )
                : { data: [], paginationInfo: { totalItems: 0, totalPages: 0 } },
        [ items, view, fields ]
    );

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
                    { items.length } { __( 'shown', 'mcp-site-manager' ) } / { total } { __( 'total', 'mcp-site-manager' ) }
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
                data={ data }
                fields={ fields }
                actions={ actions }
                view={ view }
                onChangeView={ setView }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
            />
        </>
    );
}
