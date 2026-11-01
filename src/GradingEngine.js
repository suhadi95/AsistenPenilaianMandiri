/**
 * @fileoverview Jembatan Integrasi Mesin Penilaian (Kompatibilitas Backward & Delegasi ke GradingService)
 */

const GradingEngine = {
  setupSheetTemplate() {
    return DatabaseService.initDatabase();
  },

  detectColumnIndices(sheet) {
    return {};
  },

  runSelectedRowsGrading(targetRowIndices) {
    const classes = DatabaseService.getClasses();
    if (!classes.length) {
      return { success: false, message: 'Belum ada kelas. Buka Portal Web untuk membuat kelas.' };
    }
    const classId = classes[0].id_kelas;
    const tasks = DatabaseService.getTasksByClass(classId);
    if (!tasks.length) {
      return { success: false, message: 'Belum ada tugas pada kelas aktif. Buka Portal Web untuk membuat tugas.' };
    }
    return GradingService.gradeAllStudents(tasks[0].id_tugas, true);
  },

  runBatchGrading(onlyUnassessed = true) {
    const classes = DatabaseService.getClasses();
    if (!classes.length) {
      return { success: false, message: 'Belum ada kelas. Buka Portal Web untuk membuat kelas.' };
    }
    const classId = classes[0].id_kelas;
    const tasks = DatabaseService.getTasksByClass(classId);
    if (!tasks.length) {
      return { success: false, message: 'Belum ada tugas pada kelas aktif. Buka Portal Web untuk membuat tugas.' };
    }
    return GradingService.gradeAllStudents(tasks[0].id_tugas, onlyUnassessed);
  },

  resetFailedRows() {
    const classes = DatabaseService.getClasses();
    if (!classes.length) return { success: true, count: 0 };
    const tasks = DatabaseService.getTasksByClass(classes[0].id_kelas);
    if (!tasks.length) return { success: true, count: 0 };
    return GradingService.resetFailedGrades(tasks[0].id_tugas);
  }
};
