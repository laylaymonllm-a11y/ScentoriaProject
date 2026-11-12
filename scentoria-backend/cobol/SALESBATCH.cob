>>SOURCE FORMAT FREE
       IDENTIFICATION DIVISION.
       PROGRAM-ID. SALESBATCH.
       AUTHOR. SCENTORIA.
 
       ENVIRONMENT DIVISION.
       INPUT-OUTPUT SECTION.
       FILE-CONTROL.
           SELECT SALES-INPUT
               ASSIGN TO WS-INPUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT DAILY-OUTPUT
               ASSIGN TO WS-DAILY-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT MONTHLY-OUTPUT
               ASSIGN TO WS-MONTHLY-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT PRODUCT-OUTPUT
               ASSIGN TO WS-PRODUCT-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT PRODUCT-DAILY-OUTPUT
               ASSIGN TO WS-PROD-DAILY-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT SCENT-DAILY-OUTPUT
               ASSIGN TO WS-SCENT-DAILY-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
           SELECT TXN-OUTPUT
               ASSIGN TO WS-TXN-OUT-PATH
               ORGANIZATION IS LINE SEQUENTIAL.
 
       DATA DIVISION.
       FILE SECTION.
       FD  SALES-INPUT.
       01  INPUT-RECORD.
           05  IN-DATE             PIC X(8).
           05  IN-ORDER-ID         PIC 9(8).
           05  IN-CUSTOMER         PIC X(30).
           05  IN-PRODUCT          PIC X(30).
           05  IN-BRAND            PIC X(20).
           05  IN-SCENT            PIC X(20).
           05  IN-QUANTITY         PIC 9(5).
           05  IN-LINE-TOTAL       PIC 9(10).
           05  IN-ORDER-TOTAL      PIC 9(10).
           05  IN-STATUS           PIC X(10).
               88  ORDER-COMPLETED             VALUE 'completed'.
 
           05  IN-PAYMENT          PIC X(20).
 
      *> 8 + 12 + 7 + 9 = 36 characters per line.
       FD  DAILY-OUTPUT.
       01  DAY-OUT-RECORD.
           05  OUT-DATE            PIC X(8).
           05  OUT-DAY-REVENUE     PIC 9(12).
           05  OUT-DAY-ORDERS      PIC 9(7).
           05  OUT-DAY-UNITS       PIC 9(9).
 
      *> 6 + 12 + 7 + 9 = 34 characters per line.
       FD  MONTHLY-OUTPUT.
       01  MONTH-OUT-RECORD.
           05  OUT-YEARMONTH       PIC X(6).
           05  OUT-MONTH-REVENUE   PIC 9(12).
           05  OUT-MONTH-ORDERS    PIC 9(7).
           05  OUT-MONTH-UNITS     PIC 9(9).
 
      *> 30 + 7 + 12 = 49 characters per line. Layout deliberately
      *> unchanged from the original program — the admin dashboard's
      *> all-time "Top Products" widget still reads this table.
       FD  PRODUCT-OUTPUT.
       01  PRODUCT-OUT-RECORD.
           05  OUT-PRODUCT         PIC X(30).
           05  OUT-PROD-QTY        PIC 9(7).
           05  OUT-PROD-REVENUE    PIC 9(12).
 
      *> 8 + 30 + 20 + 7 + 12 = 77 characters per line.
       FD  PRODUCT-DAILY-OUTPUT.
       01  PROD-DAY-OUT-RECORD.
           05  OUT-PD-DATE         PIC X(8).
           05  OUT-PD-PRODUCT      PIC X(30).
           05  OUT-PD-BRAND        PIC X(20).
           05  OUT-PD-QTY          PIC 9(7).
           05  OUT-PD-REVENUE      PIC 9(12).
 
      *> 8 + 20 + 7 + 12 = 47 characters per line.
       FD  SCENT-DAILY-OUTPUT.
       01  SCENT-DAY-OUT-RECORD.
           05  OUT-SD-DATE         PIC X(8).
           05  OUT-SD-SCENT        PIC X(20).
           05  OUT-SD-QTY          PIC 9(7).
           05  OUT-SD-REVENUE      PIC 9(12).
 
      *> 8 + 8 + 30 + 12 + 5 + 10 + 20 = 93 characters per line.
       FD  TXN-OUTPUT.
       01  TXN-OUT-RECORD.
           05  OUT-TX-DATE         PIC X(8).
           05  OUT-TX-ORDER-ID     PIC 9(8).
           05  OUT-TX-CUSTOMER     PIC X(30).
           05  OUT-TX-AMOUNT       PIC 9(12).
           05  OUT-TX-ITEMS        PIC 9(5).
           05  OUT-TX-STATUS       PIC X(10).
           05  OUT-TX-PAYMENT      PIC X(20).
 
       WORKING-STORAGE SECTION.
 
      *> ---- Resolved file paths -------------------------------------
       01  WS-INPUT-DIR                 PIC X(200).
       01  WS-OUTPUT-DIR                PIC X(200).
       01  WS-INPUT-PATH                PIC X(250).
       01  WS-DAILY-OUT-PATH            PIC X(250).
       01  WS-MONTHLY-OUT-PATH          PIC X(250).
       01  WS-PRODUCT-OUT-PATH          PIC X(250).
       01  WS-PROD-DAILY-OUT-PATH       PIC X(250).
       01  WS-SCENT-DAILY-OUT-PATH      PIC X(250).
       01  WS-TXN-OUT-PATH              PIC X(250).
 
       01  WS-EOF                       PIC X       VALUE 'N'.
           88  END-OF-FILE                          VALUE 'Y'.
 
      *> ---- Control break -------------------------------------------
      *> SPACES means "no day started yet", which is how the very first
      *> record avoids flushing an empty day.
       01  WS-CURRENT-DATE              PIC X(8)    VALUE SPACES.
 
      *> Per-day running totals, reset by RESET-DAY-BUCKETS.
       01  WS-DAY-REVENUE               PIC 9(12)   VALUE 0.
       01  WS-DAY-ORDERS                PIC 9(7)    VALUE 0.
       01  WS-DAY-UNITS                 PIC 9(9)    VALUE 0.
 
      *> ---- Month roll-up (spans the whole run) ---------------------
      *> 120 months = 10 years of history before this needs to grow.
      *> If you raise any OCCURS here, raise the matching WS-*-LIMIT
      *> below it too — the overflow guards compare against these.
       01  WS-MONTH-LIMIT               PIC 9(4)    VALUE 120.
       01  WS-MONTH-COUNT               PIC 9(3)    VALUE 0.
       01  WS-MONTH-TABLE.
           05  MONTH-ENTRY OCCURS 120 TIMES
                           INDEXED BY MONTH-IDX.
               10  MONTH-KEY            PIC X(6).
               10  MONTH-REVENUE        PIC 9(12).
               10  MONTH-ORDERS         PIC 9(7).
               10  MONTH-UNITS          PIC 9(9).
 
      *> ---- All-time product roll-up (spans the whole run) ----------
      *> 500 distinct products is plenty for a course-scale catalog.
       01  WS-ALLPROD-LIMIT             PIC 9(4)    VALUE 500.
       01  WS-ALLPROD-COUNT             PIC 9(4)    VALUE 0.
       01  WS-ALLPROD-TABLE.
           05  ALLPROD-ENTRY OCCURS 500 TIMES
                           INDEXED BY ALLPROD-IDX.
               10  ALLPROD-NAME         PIC X(30).
               10  ALLPROD-QTY          PIC 9(7).
               10  ALLPROD-REVENUE      PIC 9(12).
 
      *> ---- Per-day tables (reset at every control break) -----------
      *> These are the tables control-break processing keeps small:
      *> they only ever hold one calendar day's worth of activity.
       01  WS-PROD-LIMIT                PIC 9(4)    VALUE 500.
       01  WS-PROD-COUNT                PIC 9(4)    VALUE 0.
       01  WS-PROD-TABLE.
           05  PROD-ENTRY  OCCURS 500 TIMES
                           INDEXED BY PROD-IDX.
               10  PROD-NAME            PIC X(30).
               10  PROD-BRAND           PIC X(20).
               10  PROD-QTY             PIC 9(7).
               10  PROD-REVENUE         PIC 9(12).
 
       01  WS-SCENT-LIMIT               PIC 9(4)    VALUE 100.
       01  WS-SCENT-COUNT               PIC 9(3)    VALUE 0.
       01  WS-SCENT-TABLE.
           05  SCENT-ENTRY OCCURS 100 TIMES
                           INDEXED BY SCENT-IDX.
               10  SCENT-NAME           PIC X(20).
               10  SCENT-QTY            PIC 9(7).
               10  SCENT-REVENUE        PIC 9(12).
 
       01  WS-TXN-LIMIT                 PIC 9(4)    VALUE 2000.
       01  WS-TXN-COUNT                 PIC 9(4)    VALUE 0.
       01  WS-TXN-TABLE.
           05  TXN-ENTRY   OCCURS 2000 TIMES
                           INDEXED BY TXN-IDX.
               10  TXN-ORDER-ID         PIC 9(8).
               10  TXN-CUSTOMER         PIC X(30).
               10  TXN-AMOUNT           PIC 9(12).
               10  TXN-ITEMS            PIC 9(5).
               10  TXN-STATUS           PIC X(10).
               10  TXN-PAYMENT          PIC X(20).
 
      *> ---- Scratch -------------------------------------------------
       01  WS-FOUND                     PIC X       VALUE 'N'.
           88  ENTRY-FOUND                          VALUE 'Y'.
 
       01  WS-SWAPPED                   PIC X       VALUE 'N'.
 
      *> Set when any OCCURS table fills up. The run still finishes and
      *> exits 0 (a partial report beats no report), but the warning is
      *> written to SYSERR so Laravel can surface it to the admin.
       01  WS-OVERFLOW                  PIC X       VALUE 'N'.
           88  TABLE-OVERFLOWED                     VALUE 'Y'.
 
       01  WS-TEMP-MONTH-KEY            PIC X(6).
       01  WS-TEMP-MONTH-REV            PIC 9(12).
       01  WS-TEMP-MONTH-ORD            PIC 9(7).
       01  WS-TEMP-MONTH-UNI            PIC 9(9).
 
       01  WS-TEMP-PROD-NAME            PIC X(30).
       01  WS-TEMP-PROD-BRAND           PIC X(20).
       01  WS-TEMP-PROD-QTY             PIC 9(7).
       01  WS-TEMP-PROD-REV             PIC 9(12).
 
       01  WS-TEMP-SCENT-NAME           PIC X(20).
       01  WS-TEMP-SCENT-QTY            PIC 9(7).
       01  WS-TEMP-SCENT-REV            PIC 9(12).
 
       01  WS-H                         PIC 9(4).
       01  WS-I                         PIC 9(4).
       01  WS-J                         PIC 9(4).
       01  WS-K                         PIC 9(4).
 
       01  WS-READ-COUNT                PIC 9(9)    VALUE 0.
 
       PROCEDURE DIVISION.
       MAIN-LOGIC.
           PERFORM RESOLVE-PATHS
 
           OPEN INPUT  SALES-INPUT
           OPEN OUTPUT DAILY-OUTPUT
           OPEN OUTPUT PRODUCT-DAILY-OUTPUT
           OPEN OUTPUT SCENT-DAILY-OUTPUT
           OPEN OUTPUT TXN-OUTPUT
 
           PERFORM UNTIL END-OF-FILE
               READ SALES-INPUT
                   AT END
                       MOVE 'Y' TO WS-EOF
                   NOT AT END
                       ADD 1 TO WS-READ-COUNT
                       PERFORM HANDLE-RECORD
               END-READ
           END-PERFORM
 
      *> Nothing triggers the final control break, so flush by hand.
      *> Guarded on SPACES so a completely empty input file doesn't
      *> emit a bogus day row.
           IF WS-CURRENT-DATE NOT = SPACES
               PERFORM FLUSH-DAY
           END-IF
 
           CLOSE SALES-INPUT
           CLOSE DAILY-OUTPUT
           CLOSE PRODUCT-DAILY-OUTPUT
           CLOSE SCENT-DAILY-OUTPUT
           CLOSE TXN-OUTPUT
 
      *> The month and all-time-product tables accumulate across every
      *> day, so they can only be sorted and written once the last
      *> record has been read.
           PERFORM SORT-MONTHS-ASCENDING
           PERFORM WRITE-MONTHLY-OUTPUT
 
           PERFORM SORT-ALLPROD-DESCENDING
           PERFORM WRITE-PRODUCT-OUTPUT
 
           DISPLAY 'SALESBATCH: processed ' WS-READ-COUNT ' line items.'
 
           IF TABLE-OVERFLOWED
               DISPLAY 'SALESBATCH WARNING: an internal table filled up'
                   ' - some rows were skipped. Raise the OCCURS counts'
                   ' (and matching WS-*-LIMIT values) in SALESBATCH.cob'
                   ' and recompile.'
                   UPON SYSERR
           END-IF
 
           STOP RUN.
 
      *> ==============================================================
      *> Path resolution
      *> ==============================================================
       RESOLVE-PATHS.
           ACCEPT WS-INPUT-DIR  FROM ENVIRONMENT "SALES_INPUT_DIR"
           ACCEPT WS-OUTPUT-DIR FROM ENVIRONMENT "SALES_OUTPUT_DIR"
 
           STRING FUNCTION TRIM(WS-INPUT-DIR) DELIMITED BY SIZE
                  "/sales_input.dat"          DELIMITED BY SIZE
                  INTO WS-INPUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/daily_summary.dat"        DELIMITED BY SIZE
                  INTO WS-DAILY-OUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/monthly_summary.dat"      DELIMITED BY SIZE
                  INTO WS-MONTHLY-OUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/product_sales.dat"        DELIMITED BY SIZE
                  INTO WS-PRODUCT-OUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/product_daily.dat"        DELIMITED BY SIZE
                  INTO WS-PROD-DAILY-OUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/scent_daily.dat"          DELIMITED BY SIZE
                  INTO WS-SCENT-DAILY-OUT-PATH
           END-STRING
 
           STRING FUNCTION TRIM(WS-OUTPUT-DIR) DELIMITED BY SIZE
                  "/transactions.dat"         DELIMITED BY SIZE
                  INTO WS-TXN-OUT-PATH
           END-STRING.
 
      *> ==============================================================
      *> Main record handling + control break
      *> ==============================================================
       HANDLE-RECORD.
      *> First record of the run: adopt its date without flushing.
           IF WS-CURRENT-DATE = SPACES
               MOVE IN-DATE TO WS-CURRENT-DATE
           END-IF
 
      *> The date changed, so the previous day is complete. Write it
      *> out and start fresh.
           IF IN-DATE NOT = WS-CURRENT-DATE
               PERFORM FLUSH-DAY
               MOVE IN-DATE TO WS-CURRENT-DATE
           END-IF
 
      *> Every order lands in the audit log regardless of status.
           PERFORM ACCUMULATE-TXN
 
      *> Only completed orders are revenue-recognised.
           IF ORDER-COMPLETED
               ADD IN-LINE-TOTAL TO WS-DAY-REVENUE
               ADD IN-QUANTITY   TO WS-DAY-UNITS
               PERFORM ACCUMULATE-PRODUCT
               PERFORM ACCUMULATE-SCENT
               PERFORM ACCUMULATE-ALLPROD
           END-IF.
 
      *> Order-grain accumulation. One order spans several line items,
      *> so the first line seen creates the entry (and bumps the day's
      *> order count if it counts toward revenue) and later lines only
      *> increment the item tally.
       ACCUMULATE-TXN.
           MOVE 'N' TO WS-FOUND
           PERFORM VARYING TXN-IDX FROM 1 BY 1
                   UNTIL TXN-IDX > WS-TXN-COUNT
               IF TXN-ORDER-ID(TXN-IDX) = IN-ORDER-ID
                   ADD 1 TO TXN-ITEMS(TXN-IDX)
                   MOVE 'Y' TO WS-FOUND
                   SET TXN-IDX TO WS-TXN-COUNT
               END-IF
           END-PERFORM
 
           IF NOT ENTRY-FOUND
               IF WS-TXN-COUNT >= WS-TXN-LIMIT
                   MOVE 'Y' TO WS-OVERFLOW
               ELSE
                   ADD 1 TO WS-TXN-COUNT
                   SET TXN-IDX TO WS-TXN-COUNT
                   MOVE IN-ORDER-ID    TO TXN-ORDER-ID(TXN-IDX)
                   MOVE IN-CUSTOMER    TO TXN-CUSTOMER(TXN-IDX)
                   MOVE IN-ORDER-TOTAL TO TXN-AMOUNT(TXN-IDX)
                   MOVE IN-STATUS      TO TXN-STATUS(TXN-IDX)
                   MOVE IN-PAYMENT     TO TXN-PAYMENT(TXN-IDX)
                   MOVE 1              TO TXN-ITEMS(TXN-IDX)
 
                   IF ORDER-COMPLETED
                       ADD 1 TO WS-DAY-ORDERS
                   END-IF
               END-IF
           END-IF.
 
       ACCUMULATE-PRODUCT.
           MOVE 'N' TO WS-FOUND
           PERFORM VARYING PROD-IDX FROM 1 BY 1
                   UNTIL PROD-IDX > WS-PROD-COUNT
               IF PROD-NAME(PROD-IDX) = IN-PRODUCT
                   ADD IN-QUANTITY   TO PROD-QTY(PROD-IDX)
                   ADD IN-LINE-TOTAL TO PROD-REVENUE(PROD-IDX)
                   MOVE 'Y' TO WS-FOUND
                   SET PROD-IDX TO WS-PROD-COUNT
               END-IF
           END-PERFORM
 
           IF NOT ENTRY-FOUND
               IF WS-PROD-COUNT >= WS-PROD-LIMIT
                   MOVE 'Y' TO WS-OVERFLOW
               ELSE
                   ADD 1 TO WS-PROD-COUNT
                   SET PROD-IDX TO WS-PROD-COUNT
                   MOVE IN-PRODUCT    TO PROD-NAME(PROD-IDX)
                   MOVE IN-BRAND      TO PROD-BRAND(PROD-IDX)
                   MOVE IN-QUANTITY   TO PROD-QTY(PROD-IDX)
                   MOVE IN-LINE-TOTAL TO PROD-REVENUE(PROD-IDX)
               END-IF
           END-IF.
 
       ACCUMULATE-SCENT.
           MOVE 'N' TO WS-FOUND
           PERFORM VARYING SCENT-IDX FROM 1 BY 1
                   UNTIL SCENT-IDX > WS-SCENT-COUNT
               IF SCENT-NAME(SCENT-IDX) = IN-SCENT
                   ADD IN-QUANTITY   TO SCENT-QTY(SCENT-IDX)
                   ADD IN-LINE-TOTAL TO SCENT-REVENUE(SCENT-IDX)
                   MOVE 'Y' TO WS-FOUND
                   SET SCENT-IDX TO WS-SCENT-COUNT
               END-IF
           END-PERFORM
 
           IF NOT ENTRY-FOUND
               IF WS-SCENT-COUNT >= WS-SCENT-LIMIT
                   MOVE 'Y' TO WS-OVERFLOW
               ELSE
                   ADD 1 TO WS-SCENT-COUNT
                   SET SCENT-IDX TO WS-SCENT-COUNT
                   MOVE IN-SCENT      TO SCENT-NAME(SCENT-IDX)
                   MOVE IN-QUANTITY   TO SCENT-QTY(SCENT-IDX)
                   MOVE IN-LINE-TOTAL TO SCENT-REVENUE(SCENT-IDX)
               END-IF
           END-IF.
 
      *> Separate from ACCUMULATE-PRODUCT because this table survives
      *> the control break — it is the all-time roll-up the admin
      *> dashboard reads.
       ACCUMULATE-ALLPROD.
           MOVE 'N' TO WS-FOUND
           PERFORM VARYING ALLPROD-IDX FROM 1 BY 1
                   UNTIL ALLPROD-IDX > WS-ALLPROD-COUNT
               IF ALLPROD-NAME(ALLPROD-IDX) = IN-PRODUCT
                   ADD IN-QUANTITY   TO ALLPROD-QTY(ALLPROD-IDX)
                   ADD IN-LINE-TOTAL TO ALLPROD-REVENUE(ALLPROD-IDX)
                   MOVE 'Y' TO WS-FOUND
                   SET ALLPROD-IDX TO WS-ALLPROD-COUNT
               END-IF
           END-PERFORM
 
           IF NOT ENTRY-FOUND
               IF WS-ALLPROD-COUNT >= WS-ALLPROD-LIMIT
                   MOVE 'Y' TO WS-OVERFLOW
               ELSE
                   ADD 1 TO WS-ALLPROD-COUNT
                   SET ALLPROD-IDX TO WS-ALLPROD-COUNT
                   MOVE IN-PRODUCT    TO ALLPROD-NAME(ALLPROD-IDX)
                   MOVE IN-QUANTITY   TO ALLPROD-QTY(ALLPROD-IDX)
                   MOVE IN-LINE-TOTAL TO ALLPROD-REVENUE(ALLPROD-IDX)
               END-IF
           END-IF.
 
      *> ==============================================================
      *> Control break: write the finished day, then clear the buckets
      *> ==============================================================
       FLUSH-DAY.
           PERFORM WRITE-DAY-ROW
           PERFORM ACCUMULATE-MONTH-FROM-DAY
 
           PERFORM SORT-PRODUCTS-DESCENDING
           PERFORM WRITE-PRODUCT-DAILY-ROWS
 
           PERFORM SORT-SCENTS-DESCENDING
           PERFORM WRITE-SCENT-DAILY-ROWS
 
           PERFORM WRITE-TXN-ROWS
 
           PERFORM RESET-DAY-BUCKETS.
 
      *> Days with orders but no completed ones still get a row (zeros),
      *> which is harmless — Laravel zero-fills missing days anyway.
       WRITE-DAY-ROW.
           MOVE WS-CURRENT-DATE TO OUT-DATE
           MOVE WS-DAY-REVENUE  TO OUT-DAY-REVENUE
           MOVE WS-DAY-ORDERS   TO OUT-DAY-ORDERS
           MOVE WS-DAY-UNITS    TO OUT-DAY-UNITS
           WRITE DAY-OUT-RECORD.
 
      *> Month bucket is just the first 6 characters of the date
      *> (YYYYMMDD -> YYYYMM) via COBOL reference modification — no
      *> separate month field needs to travel through the input file.
       ACCUMULATE-MONTH-FROM-DAY.
           MOVE 'N' TO WS-FOUND
           PERFORM VARYING MONTH-IDX FROM 1 BY 1
                   UNTIL MONTH-IDX > WS-MONTH-COUNT
               IF MONTH-KEY(MONTH-IDX) = WS-CURRENT-DATE(1:6)
                   ADD WS-DAY-REVENUE TO MONTH-REVENUE(MONTH-IDX)
                   ADD WS-DAY-ORDERS  TO MONTH-ORDERS(MONTH-IDX)
                   ADD WS-DAY-UNITS   TO MONTH-UNITS(MONTH-IDX)
                   MOVE 'Y' TO WS-FOUND
                   SET MONTH-IDX TO WS-MONTH-COUNT
               END-IF
           END-PERFORM
 
           IF NOT ENTRY-FOUND
               IF WS-MONTH-COUNT >= WS-MONTH-LIMIT
                   MOVE 'Y' TO WS-OVERFLOW
               ELSE
                   ADD 1 TO WS-MONTH-COUNT
                   SET MONTH-IDX TO WS-MONTH-COUNT
                   MOVE WS-CURRENT-DATE(1:6) TO MONTH-KEY(MONTH-IDX)
                   MOVE WS-DAY-REVENUE  TO MONTH-REVENUE(MONTH-IDX)
                   MOVE WS-DAY-ORDERS   TO MONTH-ORDERS(MONTH-IDX)
                   MOVE WS-DAY-UNITS    TO MONTH-UNITS(MONTH-IDX)
               END-IF
           END-IF.
 
       WRITE-PRODUCT-DAILY-ROWS.
           PERFORM VARYING PROD-IDX FROM 1 BY 1
                   UNTIL PROD-IDX > WS-PROD-COUNT
               MOVE WS-CURRENT-DATE       TO OUT-PD-DATE
               MOVE PROD-NAME(PROD-IDX)   TO OUT-PD-PRODUCT
               MOVE PROD-BRAND(PROD-IDX)  TO OUT-PD-BRAND
               MOVE PROD-QTY(PROD-IDX)    TO OUT-PD-QTY
               MOVE PROD-REVENUE(PROD-IDX) TO OUT-PD-REVENUE
               WRITE PROD-DAY-OUT-RECORD
           END-PERFORM.
 
       WRITE-SCENT-DAILY-ROWS.
           PERFORM VARYING SCENT-IDX FROM 1 BY 1
                   UNTIL SCENT-IDX > WS-SCENT-COUNT
               MOVE WS-CURRENT-DATE          TO OUT-SD-DATE
               MOVE SCENT-NAME(SCENT-IDX)    TO OUT-SD-SCENT
               MOVE SCENT-QTY(SCENT-IDX)     TO OUT-SD-QTY
               MOVE SCENT-REVENUE(SCENT-IDX) TO OUT-SD-REVENUE
               WRITE SCENT-DAY-OUT-RECORD
           END-PERFORM.
 
       WRITE-TXN-ROWS.
           PERFORM VARYING TXN-IDX FROM 1 BY 1
                   UNTIL TXN-IDX > WS-TXN-COUNT
               MOVE WS-CURRENT-DATE        TO OUT-TX-DATE
               MOVE TXN-ORDER-ID(TXN-IDX)  TO OUT-TX-ORDER-ID
               MOVE TXN-CUSTOMER(TXN-IDX)  TO OUT-TX-CUSTOMER
               MOVE TXN-AMOUNT(TXN-IDX)    TO OUT-TX-AMOUNT
               MOVE TXN-ITEMS(TXN-IDX)     TO OUT-TX-ITEMS
               MOVE TXN-STATUS(TXN-IDX)    TO OUT-TX-STATUS
               MOVE TXN-PAYMENT(TXN-IDX)   TO OUT-TX-PAYMENT
               WRITE TXN-OUT-RECORD
           END-PERFORM.
 
      *> Zeroing the counts is enough — nothing reads past the count,
      *> so leftover bytes in the tables are never visible.
       RESET-DAY-BUCKETS.
           MOVE 0 TO WS-DAY-REVENUE
           MOVE 0 TO WS-DAY-ORDERS
           MOVE 0 TO WS-DAY-UNITS
           MOVE 0 TO WS-PROD-COUNT
           MOVE 0 TO WS-SCENT-COUNT
           MOVE 0 TO WS-TXN-COUNT.
 
       SORT-MONTHS-ASCENDING.
           MOVE 'Y' TO WS-SWAPPED
           PERFORM UNTIL WS-SWAPPED = 'N'
               MOVE 'N' TO WS-SWAPPED
               PERFORM VARYING WS-H FROM 1 BY 1
                       UNTIL WS-H >= WS-MONTH-COUNT
                   IF MONTH-KEY(WS-H) > MONTH-KEY(WS-H + 1)
                       MOVE MONTH-KEY(WS-H)       TO WS-TEMP-MONTH-KEY
                       MOVE MONTH-REVENUE(WS-H)   TO WS-TEMP-MONTH-REV
                       MOVE MONTH-ORDERS(WS-H)    TO WS-TEMP-MONTH-ORD
                       MOVE MONTH-UNITS(WS-H)     TO WS-TEMP-MONTH-UNI
 
                       MOVE MONTH-KEY(WS-H + 1)     TO MONTH-KEY(WS-H)
                       MOVE MONTH-REVENUE(WS-H + 1) TO MONTH-REVENUE(WS-H)
                       MOVE MONTH-ORDERS(WS-H + 1)  TO MONTH-ORDERS(WS-H)
                       MOVE MONTH-UNITS(WS-H + 1)   TO MONTH-UNITS(WS-H)
 
                       MOVE WS-TEMP-MONTH-KEY TO MONTH-KEY(WS-H + 1)
                       MOVE WS-TEMP-MONTH-REV TO MONTH-REVENUE(WS-H + 1)
                       MOVE WS-TEMP-MONTH-ORD TO MONTH-ORDERS(WS-H + 1)
                       MOVE WS-TEMP-MONTH-UNI TO MONTH-UNITS(WS-H + 1)
 
                       MOVE 'Y' TO WS-SWAPPED
                   END-IF
               END-PERFORM
           END-PERFORM.
 
      *> Per-day product table, sorted by units descending purely so
      *> product_daily.dat is readable by eye. Laravel re-ranks after
      *> summing across the admin's chosen date range.
       SORT-PRODUCTS-DESCENDING.
           MOVE 'Y' TO WS-SWAPPED
           PERFORM UNTIL WS-SWAPPED = 'N'
               MOVE 'N' TO WS-SWAPPED
               PERFORM VARYING WS-I FROM 1 BY 1
                       UNTIL WS-I >= WS-PROD-COUNT
                   IF PROD-QTY(WS-I) < PROD-QTY(WS-I + 1)
                       MOVE PROD-NAME(WS-I)    TO WS-TEMP-PROD-NAME
                       MOVE PROD-BRAND(WS-I)   TO WS-TEMP-PROD-BRAND
                       MOVE PROD-QTY(WS-I)     TO WS-TEMP-PROD-QTY
                       MOVE PROD-REVENUE(WS-I) TO WS-TEMP-PROD-REV
 
                       MOVE PROD-NAME(WS-I + 1)    TO PROD-NAME(WS-I)
                       MOVE PROD-BRAND(WS-I + 1)   TO PROD-BRAND(WS-I)
                       MOVE PROD-QTY(WS-I + 1)     TO PROD-QTY(WS-I)
                       MOVE PROD-REVENUE(WS-I + 1) TO PROD-REVENUE(WS-I)
 
                       MOVE WS-TEMP-PROD-NAME  TO PROD-NAME(WS-I + 1)
                       MOVE WS-TEMP-PROD-BRAND TO PROD-BRAND(WS-I + 1)
                       MOVE WS-TEMP-PROD-QTY   TO PROD-QTY(WS-I + 1)
                       MOVE WS-TEMP-PROD-REV   TO PROD-REVENUE(WS-I + 1)
 
                       MOVE 'Y' TO WS-SWAPPED
                   END-IF
               END-PERFORM
           END-PERFORM.
 
       SORT-SCENTS-DESCENDING.
           MOVE 'Y' TO WS-SWAPPED
           PERFORM UNTIL WS-SWAPPED = 'N'
               MOVE 'N' TO WS-SWAPPED
               PERFORM VARYING WS-J FROM 1 BY 1
                       UNTIL WS-J >= WS-SCENT-COUNT
                   IF SCENT-REVENUE(WS-J) < SCENT-REVENUE(WS-J + 1)
                       MOVE SCENT-NAME(WS-J)    TO WS-TEMP-SCENT-NAME
                       MOVE SCENT-QTY(WS-J)     TO WS-TEMP-SCENT-QTY
                       MOVE SCENT-REVENUE(WS-J) TO WS-TEMP-SCENT-REV
 
                       MOVE SCENT-NAME(WS-J + 1)    TO SCENT-NAME(WS-J)
                       MOVE SCENT-QTY(WS-J + 1)     TO SCENT-QTY(WS-J)
                       MOVE SCENT-REVENUE(WS-J + 1) TO SCENT-REVENUE(WS-J)
 
                       MOVE WS-TEMP-SCENT-NAME TO SCENT-NAME(WS-J + 1)
                       MOVE WS-TEMP-SCENT-QTY  TO SCENT-QTY(WS-J + 1)
                       MOVE WS-TEMP-SCENT-REV  TO SCENT-REVENUE(WS-J + 1)
 
                       MOVE 'Y' TO WS-SWAPPED
                   END-IF
               END-PERFORM
           END-PERFORM.
 
      *> This one's order IS the contract: the ingest step reads the
      *> rows in sequence and assigns rank 1, 2, 3... from the file
      *> position, so product_sales.dat must be units-descending.
       SORT-ALLPROD-DESCENDING.
           MOVE 'Y' TO WS-SWAPPED
           PERFORM UNTIL WS-SWAPPED = 'N'
               MOVE 'N' TO WS-SWAPPED
               PERFORM VARYING WS-K FROM 1 BY 1
                       UNTIL WS-K >= WS-ALLPROD-COUNT
                   IF ALLPROD-QTY(WS-K) < ALLPROD-QTY(WS-K + 1)
                       MOVE ALLPROD-NAME(WS-K)    TO WS-TEMP-PROD-NAME
                       MOVE ALLPROD-QTY(WS-K)     TO WS-TEMP-PROD-QTY
                       MOVE ALLPROD-REVENUE(WS-K) TO WS-TEMP-PROD-REV
 
                       MOVE ALLPROD-NAME(WS-K + 1)    TO ALLPROD-NAME(WS-K)
                       MOVE ALLPROD-QTY(WS-K + 1)     TO ALLPROD-QTY(WS-K)
                       MOVE ALLPROD-REVENUE(WS-K + 1) TO ALLPROD-REVENUE(WS-K)
 
                       MOVE WS-TEMP-PROD-NAME TO ALLPROD-NAME(WS-K + 1)
                       MOVE WS-TEMP-PROD-QTY  TO ALLPROD-QTY(WS-K + 1)
                       MOVE WS-TEMP-PROD-REV  TO ALLPROD-REVENUE(WS-K + 1)
 
                       MOVE 'Y' TO WS-SWAPPED
                   END-IF
               END-PERFORM
           END-PERFORM.
 
      *> ==============================================================
      *> End-of-run writers
      *> ==============================================================
       WRITE-MONTHLY-OUTPUT.
           OPEN OUTPUT MONTHLY-OUTPUT
           PERFORM VARYING MONTH-IDX FROM 1 BY 1
                   UNTIL MONTH-IDX > WS-MONTH-COUNT
               MOVE MONTH-KEY(MONTH-IDX)     TO OUT-YEARMONTH
               MOVE MONTH-REVENUE(MONTH-IDX) TO OUT-MONTH-REVENUE
               MOVE MONTH-ORDERS(MONTH-IDX)  TO OUT-MONTH-ORDERS
               MOVE MONTH-UNITS(MONTH-IDX)   TO OUT-MONTH-UNITS
               WRITE MONTH-OUT-RECORD
           END-PERFORM
           CLOSE MONTHLY-OUTPUT.
 
       WRITE-PRODUCT-OUTPUT.
           OPEN OUTPUT PRODUCT-OUTPUT
           PERFORM VARYING ALLPROD-IDX FROM 1 BY 1
                   UNTIL ALLPROD-IDX > WS-ALLPROD-COUNT
               MOVE ALLPROD-NAME(ALLPROD-IDX)    TO OUT-PRODUCT
               MOVE ALLPROD-QTY(ALLPROD-IDX)     TO OUT-PROD-QTY
               MOVE ALLPROD-REVENUE(ALLPROD-IDX) TO OUT-PROD-REVENUE
               WRITE PRODUCT-OUT-RECORD
           END-PERFORM
           CLOSE PRODUCT-OUTPUT.
 